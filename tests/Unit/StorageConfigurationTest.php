<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Storage\AwsS3OssStorage;
use App\Storage\OssConfiguration;
use Aws\S3\S3Client;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StorageConfigurationTest extends TestCase
{
    private function values(): array
    {
        return ['endpoint' => 'https://s3.oss-cn-hangzhou.aliyuncs.com', 'region' => 'cn-hangzhou',
            'bucket' => 'classroom-unit-test', 'key' => 'TEST_ONLY_KEY', 'secret' => 'TEST_ONLY_SECRET',
            'prefix' => 'classroom', 'presign_seconds' => 120];
    }

    public function test_configuration_version_tracks_destination_not_rotated_credentials(): void
    {
        $original = new OssConfiguration($this->values());
        $rotated = new OssConfiguration([...$this->values(), 'secret' => 'ROTATED_TEST_SECRET']);
        $newPrefix = new OssConfiguration([...$this->values(), 'prefix' => 'classroom-v2']);
        self::assertSame($original->version(), $rotated->version());
        self::assertNotSame($original->version(), $newPrefix->version());
    }

    public function test_sdk_presigns_v4_virtual_host_without_network(): void
    {
        $config = new OssConfiguration($this->values());
        $sdk = new S3Client(AwsS3OssStorage::clientOptions($config));
        $store = new AwsS3OssStorage($config, $sdk);
        $url = $store->presignedGet($config->objectKey('lessons/1/archive/original.m4a'));
        self::assertSame('classroom-unit-test.s3.oss-cn-hangzhou.aliyuncs.com', parse_url($url, PHP_URL_HOST));
        self::assertSame('/classroom/lessons/1/archive/original.m4a', parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('AWS4-HMAC-SHA256', $query['X-Amz-Algorithm']);
        self::assertSame('120', $query['X-Amz-Expires']);
        self::assertStringNotContainsString('TEST_ONLY_SECRET', $url);
    }

    public function test_arbitrary_ssrf_endpoint_is_rejected(): void
    {
        foreach (['http://s3.oss-cn-hangzhou.aliyuncs.com', 'https://127.0.0.1',
            'https://s3.oss-cn-hangzhou.aliyuncs.com.evil.example',
            'https://s3.oss-cn-hangzhou.aliyuncs.com@localhost',
            'https://s3.oss-cn-beijing.aliyuncs.com',
            'https://s3.oss-cn-hangzhou.aliyuncs.com/unsafe',
            'https://s3.oss-cn-hangzhou.aliyuncs.com?x=1',
            'https://s3.oss-cn-hangzhou-internal.aliyuncs.com'] as $endpoint) {
            try {
                new OssConfiguration([...$this->values(), 'endpoint' => $endpoint]);
                self::fail('Unsafe OSS endpoint accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_object_keys_cannot_escape_managed_prefix(): void
    {
        $config = new OssConfiguration($this->values());
        self::assertSame('classroom/lessons/1/segments/2/3.pcm', $config->objectKey('lessons/1/segments/2/3.pcm'));
        $this->expectException(InvalidArgumentException::class);
        $config->objectKey('../existing-object');
    }

    public function test_private_playback_lifetime_is_bounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OssConfiguration([...$this->values(), 'presign_seconds' => 86400]);
    }

    public function test_missing_credentials_are_reported_by_name_only(): void
    {
        self::assertSame(['key', 'secret'], OssConfiguration::missing([
            'endpoint' => 'https://s3.oss-cn-hangzhou.aliyuncs.com', 'region' => 'cn-hangzhou', 'bucket' => 'valid-bucket']));
    }
}
