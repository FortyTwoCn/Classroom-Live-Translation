<?php

declare(strict_types=1);

namespace App\Storage;

use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use RuntimeException;

final class AwsS3OssStorage
{
    public readonly OssConfiguration $configuration;
    private S3ClientInterface $client;
    public const PART_BYTES = 8 * 1024 * 1024;

    public function __construct(?OssConfiguration $configuration = null, ?S3ClientInterface $client = null)
    {
        $this->configuration = $configuration ?? new OssConfiguration(config('classroom.oss'));
        $this->client = $client ?? new S3Client(self::clientOptions($this->configuration));
    }

    public static function clientOptions(OssConfiguration $config): array
    {
        $credentials = ['key' => $config->key, 'secret' => $config->secret];
        if ($config->token !== null) {
            $credentials['token'] = $config->token;
        }
        return [
            'version' => '2006-03-01', 'region' => $config->region,
            'endpoint' => $config->endpoint, 'credentials' => $credentials,
            'signature_version' => 'v4', 'use_path_style_endpoint' => false,
            'use_accelerate_endpoint' => false, 'use_dual_stack_endpoint' => false,
            // OSS does not support the SDK's optional AWS trailing checksum/chunk encoding.
            // Every upload below has a known Content-Length and a seekable body.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'retries' => 2,
            'http' => ['connect_timeout' => 5, 'timeout' => 60,
                'allow_redirects' => false, 'verify' => true],
        ];
    }

    public function put(string $key, string $contents, string $mime = 'application/octet-stream', array $metadata = []): void
    {
        $this->client->putObject(['Bucket' => $this->configuration->bucket, 'Key' => $key,
            'Body' => $contents, 'ContentLength' => strlen($contents), 'ContentType' => $mime,
            'ContentMD5' => base64_encode(md5($contents, true)), 'Metadata' => $metadata]);
    }

    public function upload(string $key, string $file, string $mime, string $sha256): array
    {
        $bytes = filesize($file);
        if ($bytes === false || $bytes === 0 || !hash_equals($sha256, hash_file('sha256', $file))) {
            throw new RuntimeException('storage_input_checksum_invalid');
        }
        if ($bytes <= self::PART_BYTES) {
            $stream = fopen($file, 'rb');
            if ($stream === false) {
                throw new RuntimeException('storage_input_missing');
            }
            try {
                $this->client->putObject(['Bucket' => $this->configuration->bucket, 'Key' => $key,
                    'Body' => $stream, 'ContentLength' => $bytes, 'ContentType' => $mime,
                    'ContentMD5' => base64_encode(md5_file($file, true)), 'Metadata' => ['sha256' => $sha256]]);
            } finally {
                fclose($stream);
            }
        } else {
            $this->multipart($key, $file, $mime, ['sha256' => $sha256]);
        }
        $head = $this->head($key);
        if ($head['bytes'] !== $bytes || !hash_equals($sha256, $head['sha256'] ?? '')) {
            throw new RuntimeException('storage_upload_verification_failed');
        }
        return $head;
    }

    public function multipart(string $key, string $file, string $mime, array $metadata = []): void
    {
        $stream = fopen($file, 'rb');
        if ($stream === false) {
            throw new RuntimeException('storage_input_missing');
        }
        $uploadId = null;
        try {
            $result = $this->client->createMultipartUpload(['Bucket' => $this->configuration->bucket,
                'Key' => $key, 'ContentType' => $mime, 'Metadata' => $metadata]);
            $uploadId = (string) $result['UploadId'];
            $parts = [];
            while (!feof($stream)) {
                $body = fread($stream, self::PART_BYTES);
                if ($body === false) {
                    throw new RuntimeException('storage_input_read_failed');
                }
                if ($body === '') {
                    break;
                }
                $partNumber = count($parts) + 1;
                if ($partNumber > 10000) {
                    throw new RuntimeException('storage_multipart_limit');
                }
                $result = $this->client->uploadPart(['Bucket' => $this->configuration->bucket,
                    'Key' => $key, 'UploadId' => $uploadId, 'PartNumber' => $partNumber,
                    'Body' => $body, 'ContentLength' => strlen($body), 'ContentMD5' => base64_encode(md5($body, true))]);
                $parts[] = ['PartNumber' => $partNumber, 'ETag' => (string) $result['ETag']];
            }
            if ($parts === []) {
                throw new RuntimeException('storage_input_empty');
            }
            $this->client->completeMultipartUpload(['Bucket' => $this->configuration->bucket,
                'Key' => $key, 'UploadId' => $uploadId, 'MultipartUpload' => ['Parts' => $parts]]);
        } catch (\Throwable $error) {
            if ($uploadId !== null) {
                try {
                    $this->client->abortMultipartUpload(['Bucket' => $this->configuration->bucket,
                        'Key' => $key, 'UploadId' => $uploadId]);
                } catch (\Throwable) {
                    // Account policy must also expire incomplete uploads; original error wins.
                }
            }
            throw $error;
        } finally {
            fclose($stream);
        }
    }

    public function head(string $key): array
    {
        $result = $this->client->headObject(['Bucket' => $this->configuration->bucket, 'Key' => $key]);
        return ['bytes' => (int) $result['ContentLength'], 'sha256' => $result['Metadata']['sha256'] ?? null,
            'mime' => (string) ($result['ContentType'] ?? 'application/octet-stream')];
    }

    public function read(string $key, ?string $range = null): string
    {
        $params = ['Bucket' => $this->configuration->bucket, 'Key' => $key];
        if ($range !== null) {
            if (!preg_match('/^bytes=\d+-\d*$/D', $range)) {
                throw new RuntimeException('storage_range_invalid');
            }
            $params['Range'] = $range;
        }
        return (string) $this->client->getObject($params)['Body'];
    }

    public function delete(string $key): void
    {
        $this->client->deleteObject(['Bucket' => $this->configuration->bucket, 'Key' => $key]);
    }

    /** Bounded streaming download; neither S3 headers nor file names are trusted. */
    public function downloadVerified(string $key,string $destination,int $bytes,string $sha256):void
    {
        if($bytes<=0||$bytes>320000||!preg_match('/^[a-f0-9]{64}$/D',$sha256))throw new RuntimeException('recording_download_bounds_invalid');
        $head=$this->head($key);if($head['bytes']!==$bytes||!hash_equals($sha256,$head['sha256']??''))throw new RuntimeException('recording_remote_checksum_failed');
        $body=$this->client->getObject(['Bucket'=>$this->configuration->bucket,'Key'=>$key])['Body'];$out=fopen($destination,'xb');if(!$out){$body->close();throw new RuntimeException('recording_output_failed');}$hash=hash_init('sha256');$total=0;
        try{while(!$body->eof()){$data=$body->read(8192);if($data==='')break;$total+=strlen($data);if($total>$bytes||fwrite($out,$data)!==strlen($data))throw new RuntimeException('recording_download_length_invalid');hash_update($hash,$data);}if($total!==$bytes||!hash_equals($sha256,hash_final($hash)))throw new RuntimeException('recording_download_checksum_failed');if(!fflush($out)||!fsync($out))throw new RuntimeException('recording_output_sync_failed');}finally{fclose($out);$body->close();}
    }

    /** Caller must perform lesson authorization immediately before signing. */
    public function presignedGet(string $key, ?string $downloadName = null): string
    {
        $params = ['Bucket' => $this->configuration->bucket, 'Key' => $key];
        if ($downloadName !== null) {
            if (strlen($downloadName) > 500 || preg_match('/[\r\n\x00]/', $downloadName)) throw new RuntimeException('storage_download_name_invalid');
            $params['ResponseContentDisposition'] = "attachment; filename=\"recording.m4a\"; filename*=UTF-8''".rawurlencode($downloadName);
        }
        $command = $this->client->getCommand('GetObject', $params);
        return (string) $this->client->createPresignedRequest($command,
            '+'.$this->configuration->presignSeconds.' seconds')->getUri();
    }
}
