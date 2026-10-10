#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Storage\AwsS3OssStorage;
use App\Storage\OssConfiguration;
use Aws\Exception\AwsException;
use GuzzleHttp\Client;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$values = config('classroom.oss');
$report = ['test_type' => 'real_oss_s3', 'status' => 'running',
    'started_at' => gmdate(DATE_ATOM), 'steps' => []];
$missing = OssConfiguration::missing($values);
if ($missing !== []) {
    $report['status'] = 'unverified';
    $report['reason'] = 'missing_configuration';
    $report['missing_fields'] = $missing;
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(2);
}

$store = null;
$keys = [];
$stepName = 'configuration';
$failed = false;
$temp = null;
$step = static function (string $name, callable $callback) use (&$stepName, &$report): mixed {
    $stepName = $name;
    $start = hrtime(true);
    $value = $callback();
    $report['steps'][] = ['step' => $name, 'status' => 'passed',
        'elapsed_ms' => (int) ((hrtime(true) - $start) / 1000000)];
    return $value;
};
try {
    $store = $step('configuration', static fn () => new AwsS3OssStorage(new OssConfiguration($values)));
    $base = '_connection-tests/'.bin2hex(random_bytes(16));
    $keys = [$store->configuration->objectKey($base.'/small.bin'),
        $store->configuration->objectKey($base.'/multipart.bin')];
    $payload = random_bytes(4096);
    $sha = hash('sha256', $payload);
    $step('put', static fn () => $store->put($keys[0], $payload, 'application/octet-stream', ['sha256' => $sha]));
    $step('get_and_sha256', static function () use ($store, $keys, $sha): void {
        if (!hash_equals($sha, hash('sha256', $store->read($keys[0])))) throw new RuntimeException('probe_checksum_failed');
    });
    $step('head', static function () use ($store, $keys, $payload, $sha): void {
        $head = $store->head($keys[0]);
        if ($head['bytes'] !== strlen($payload) || !hash_equals($sha, $head['sha256'] ?? '')) throw new RuntimeException('probe_head_failed');
    });
    $http = new Client(['timeout' => 30, 'connect_timeout' => 5, 'allow_redirects' => false,
        'http_errors' => false, 'verify' => true]);
    $signed = $store->presignedGet($keys[0]);
    $step('signed_get', static function () use ($http, $signed, $sha): void {
        $response = $http->get($signed);
        if ($response->getStatusCode() !== 200 || !hash_equals($sha, hash('sha256', (string) $response->getBody()))) {
            throw new RuntimeException('probe_signed_get_failed');
        }
    });
    $step('signed_get_range', static function () use ($http, $signed, $payload): void {
        $response = $http->get($signed, ['headers' => ['Range' => 'bytes=32-95']]);
        if ($response->getStatusCode() !== 206 || (string) $response->getBody() !== substr($payload, 32, 64)
            || $response->getHeaderLine('Content-Range') !== 'bytes 32-95/'.strlen($payload)) {
            throw new RuntimeException('probe_range_failed');
        }
    });
    $step('unsigned_get_denied', static function () use ($http, $signed): void {
        $unsigned = explode('?', $signed, 2)[0];
        if (!in_array($http->get($unsigned)->getStatusCode(), [401, 403, 404], true)) {
            throw new RuntimeException('probe_bucket_not_private');
        }
    });
    $temp = tempnam(storage_path('app'), 'oss-probe-');
    if ($temp === false) throw new RuntimeException('probe_temp_file_failed');
    $multipartData = str_repeat('OSS S3 compatibility probe; ', 360000); // > 8 MiB, two known-length parts.
    if (file_put_contents($temp, $multipartData) !== strlen($multipartData)) throw new RuntimeException('probe_temp_file_failed');
    $largeSha = hash('sha256', $multipartData);
    $step('multipart_put_and_head', static fn () => $store->upload($keys[1], $temp, 'application/octet-stream', $largeSha));
    $step('multipart_get_and_sha256', static function () use ($store, $keys, $largeSha): void {
        if (!hash_equals($largeSha, hash('sha256', $store->read($keys[1])))) throw new RuntimeException('probe_multipart_checksum_failed');
    });
    $step('delete', static function () use ($store, $keys): void {
        foreach ($keys as $key) $store->delete($key);
    });
    $step('deleted_objects_absent', static function () use ($store, $keys): void {
        foreach ($keys as $key) {
            try {
                $store->head($key);
            } catch (AwsException $error) {
                if ($error->getStatusCode() === 404) continue;
                throw $error;
            }
            throw new RuntimeException('probe_delete_not_confirmed');
        }
    });
} catch (Throwable $error) {
    $failed = true;
    $entry = ['step' => $stepName, 'status' => 'failed', 'error_type' => get_class($error)];
    if ($error instanceof AwsException) {
        $entry['provider_error_code'] = $error->getAwsErrorCode();
        $entry['http_status'] = $error->getStatusCode();
    } elseif ($error instanceof RuntimeException || $error instanceof InvalidArgumentException) {
        // Only our stable diagnostic codes; never print arbitrary request URLs or credentials.
        if (preg_match('/^[a-z_]+$/D', $error->getMessage())) $entry['error_code'] = $error->getMessage();
    }
    $report['steps'][] = $entry;
} finally {
    if ($store !== null) {
        foreach ($keys as $key) {
            try {
                $store->delete($key);
            } catch (Throwable $error) {
                $failed = true;
                $report['steps'][] = ['step' => 'cleanup', 'status' => 'failed', 'error_type' => get_class($error)];
            }
        }
    }
    if (is_string($temp) && is_file($temp)) unlink($temp);
}
$report['status'] = $failed ? 'failed' : 'passed';
$report['finished_at'] = gmdate(DATE_ATOM);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
exit($failed ? 1 : 0);
