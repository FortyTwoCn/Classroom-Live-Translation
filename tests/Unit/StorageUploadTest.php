<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Storage\AwsS3OssStorage;
use App\Storage\OssConfiguration;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StorageUploadTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) if (is_file($file)) unlink($file);
        parent::tearDown();
    }

    private function file(string $data): string
    {
        $file = tempnam(dirname(__DIR__, 2).'/storage/app', 'storage-test-');
        file_put_contents($file, $data);
        $this->files[] = $file;
        return $file;
    }

    private function config(): OssConfiguration
    {
        return new OssConfiguration(['endpoint' => 'https://s3.oss-cn-hangzhou.aliyuncs.com',
            'region' => 'cn-hangzhou', 'bucket' => 'classroom-test', 'key' => 'TEST_ONLY_KEY',
            'secret' => 'TEST_ONLY_SECRET']);
    }

    public function test_upload_verifies_head_and_never_uses_chunk_encoding(): void
    {
        $data = 'real-byte-fixture';
        $hash = hash('sha256', $data);
        $commands = [];
        $handler = static function ($command, $request) use (&$commands, $data, $hash) {
            $commands[] = $command->getName();
            self::assertSame('', $request->getHeaderLine('Transfer-Encoding'));
            self::assertStringNotContainsString('aws-chunked', $request->getHeaderLine('Content-Encoding'));
            if ($command->getName() === 'PutObject') {
                self::assertSame((string) strlen($data), $request->getHeaderLine('Content-Length'));
                self::assertSame(base64_encode(md5($data, true)), $request->getHeaderLine('Content-MD5'));
                return Create::promiseFor(new Result());
            }
            return Create::promiseFor(new Result(['ContentLength' => strlen($data), 'ContentType' => 'audio/mp4',
                'Metadata' => ['sha256' => $hash]]));
        };
        $config = $this->config();
        $store = new AwsS3OssStorage($config, new S3Client([...AwsS3OssStorage::clientOptions($config), 'handler' => $handler]));
        $result = $store->upload('classroom/test.m4a', $this->file($data), 'audio/mp4', $hash);
        self::assertSame(['PutObject', 'HeadObject'], $commands);
        self::assertSame(strlen($data), $result['bytes']);
    }

    public function test_multipart_has_bounded_parts_and_abort_on_failure(): void
    {
        $commands = [];
        $data = str_repeat('X', AwsS3OssStorage::PART_BYTES + 100);
        $handler = static function ($command, $request) use (&$commands) {
            $commands[] = $command->getName();
            if ($command->getName() === 'CreateMultipartUpload') return Create::promiseFor(new Result(['UploadId' => 'test-upload']));
            if ($command->getName() === 'UploadPart') {
                self::assertLessThanOrEqual(AwsS3OssStorage::PART_BYTES, $command['ContentLength']);
                self::assertSame('', $request->getHeaderLine('Transfer-Encoding'));
                throw new RuntimeException('simulated_network_failure');
            }
            return Create::promiseFor(new Result());
        };
        $config = $this->config();
        $store = new AwsS3OssStorage($config, new S3Client([...AwsS3OssStorage::clientOptions($config),
            'handler' => $handler, 'retries' => 0]));
        try {
            $store->upload('classroom/test.m4a', $this->file($data), 'audio/mp4', hash('sha256', $data));
            self::fail('Failed multipart reported success');
        } catch (RuntimeException $error) {
            self::assertSame('simulated_network_failure', $error->getMessage());
        }
        self::assertSame(['CreateMultipartUpload', 'UploadPart', 'AbortMultipartUpload'], $commands);
    }

    public function test_wrong_head_checksum_does_not_report_verified_upload(): void
    {
        $data = 'fixture';
        $config = $this->config();
        $handler = static fn ($command) => Create::promiseFor(new Result($command->getName() === 'HeadObject'
            ? ['ContentLength' => 7, 'Metadata' => ['sha256' => str_repeat('0', 64)]] : []));
        $store = new AwsS3OssStorage($config, new S3Client([...AwsS3OssStorage::clientOptions($config), 'handler' => $handler]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('storage_upload_verification_failed');
        $store->upload('classroom/test.m4a', $this->file($data), 'audio/mp4', hash('sha256', $data));
    }
}
