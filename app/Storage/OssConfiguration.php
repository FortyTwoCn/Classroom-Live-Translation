<?php

declare(strict_types=1);

namespace App\Storage;

use InvalidArgumentException;

final readonly class OssConfiguration
{
    public string $endpoint;
    public string $region;
    public string $bucket;
    public string $key;
    public string $secret;
    public ?string $token;
    public string $prefix;
    public int $presignSeconds;

    public function __construct(?array $values = null)
    {
        $values ??= config('classroom.oss');
        $this->endpoint = rtrim((string) ($values['endpoint'] ?? ''), '/');
        $this->region = (string) ($values['region'] ?? '');
        $this->bucket = (string) ($values['bucket'] ?? '');
        $this->key = (string) ($values['key'] ?? '');
        $this->secret = (string) ($values['secret'] ?? '');
        $this->token = ($values['token'] ?? '') !== '' ? (string) $values['token'] : null;
        $this->prefix = trim((string) ($values['prefix'] ?? 'classroom'), '/');
        $this->presignSeconds = (int) ($values['presign_seconds'] ?? 300);
        $this->validate();
    }

    public static function missing(array $values): array
    {
        return array_values(array_filter(['endpoint', 'region', 'bucket', 'key', 'secret'],
            static fn (string $key): bool => trim((string) ($values[$key] ?? '')) === ''));
    }

    private function validate(): void
    {
        if (self::missing(['endpoint' => $this->endpoint, 'region' => $this->region,
            'bucket' => $this->bucket, 'key' => $this->key, 'secret' => $this->secret]) !== []) {
            throw new InvalidArgumentException('oss_configuration_incomplete');
        }
        $url = parse_url($this->endpoint);
        if ($url === false || ($url['scheme'] ?? '') !== 'https'
            || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment'])
            || (isset($url['port']) && $url['port'] !== 443)
            || !in_array($url['path'] ?? '', ['', '/'], true)) {
            throw new InvalidArgumentException('oss_endpoint_not_allowed');
        }
        // Only Aliyun-owned public regional S3 hosts. Internal, accelerate and custom
        // hosts require a separate reviewed policy; arbitrary URLs never reach the SDK.
        if (!preg_match('/^[a-z]{2}-[a-z0-9]+(?:-[a-z0-9]+)*$/D', $this->region)) {
            throw new InvalidArgumentException('oss_region_not_allowed');
        }
        $allowed = ['s3.oss-'.$this->region.'.aliyuncs.com', 'oss-'.$this->region.'.aliyuncs.com'];
        if (!in_array(strtolower((string) ($url['host'] ?? '')), $allowed, true)) {
            throw new InvalidArgumentException('oss_endpoint_region_mismatch');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D', $this->bucket)) {
            throw new InvalidArgumentException('oss_bucket_not_allowed');
        }
        if (!preg_match('/^[a-zA-Z0-9_\/-]*$/D', $this->prefix) || str_contains($this->prefix, '..')) {
            throw new InvalidArgumentException('oss_prefix_not_allowed');
        }
        if ($this->presignSeconds < 30 || $this->presignSeconds > 900) {
            throw new InvalidArgumentException('oss_presign_lifetime_not_allowed');
        }
    }

    public function objectKey(string $relative): string
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')
            || !preg_match('/^[a-zA-Z0-9_\/.\-]+$/D', $relative)) {
            throw new InvalidArgumentException('oss_object_key_not_allowed');
        }
        return $this->prefix === '' ? $relative : $this->prefix.'/'.$relative;
    }

    /** Public configuration identity: credentials are intentionally excluded. */
    public function version(): string
    {
        return hash('sha256', json_encode([$this->endpoint, $this->region, $this->bucket, $this->prefix], JSON_THROW_ON_ERROR));
    }
}
