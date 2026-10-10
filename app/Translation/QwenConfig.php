<?php

declare(strict_types=1);

namespace App\Translation;

use InvalidArgumentException;

/** Configuration is server-only. Never serialize this object into an API response. */
final class QwenConfig
{
    /** Application resource cap, not an advertised provider limit. Includes event_id. */
    public const MAX_SESSION_UPDATE_BYTES = 65536;
    public const MAX_PHRASE_SOURCE_BYTES = 256;
    public const MAX_PHRASE_TARGET_BYTES = 64;
    public readonly string $model;
    public readonly string $region;
    public readonly string $workspaceId;
    public readonly string $endpoint;
    public readonly string $apiKey;
    public readonly int $connectTimeout;
    public readonly int $finishTimeout;
    private readonly bool $testEndpoint;
    private readonly array $phrases;

    public const LANGUAGES = ['zh','en','ar','de','fr','es','pt','id','it','ko','ru','th','vi','ja','tr','hi','ms','nl','ur','nb','sv','da','he','fi','pl','is','cs','fil','fa','yue','el','af','ast','be','bg','bn','bs','ca','ceb','et','gl','gu','hr','hu','jv','kk','kn','ky','lv','mk','ml','mr','pa','ro','sk','sl','sw','tg','az','uk'];

    public function __construct(array $values, bool $isTesting = false)
    {
        $this->model = trim((string) ($values['model'] ?? 'qwen3.8-livetranslate-flash-realtime'));
        $this->region = trim((string) ($values['region'] ?? 'ap-southeast-1'));
        $this->workspaceId = trim((string) ($values['workspace_id'] ?? ''));
        $endpoint = trim((string) ($values['endpoint'] ?? ''));
        // The documented regional Workspace host is a safe default; an explicit
        // override remains configurable and must pass the same allowlist checks.
        if ($endpoint === '' && $this->workspaceId !== '' && in_array($this->region, ['cn-beijing','ap-southeast-1'], true)) {
            $endpoint = 'wss://'.$this->workspaceId.'.'.$this->region.'.maas.aliyuncs.com/api-ws/v1/realtime';
        }
        $this->endpoint = $endpoint;
        $this->apiKey = trim((string) ($values['api_key'] ?? ''));
        $this->testEndpoint = ($values['allow_test_endpoint'] ?? false) === true && $isTesting;
        $this->connectTimeout = max(1, min(60, (int) ($values['connect_timeout'] ?? 20)));
        $this->finishTimeout = max(1, min(60, (int) ($values['finish_timeout'] ?? 20)));
        $this->phrases = $values['phrases'] ?? [];
    }

    public static function fromArray(array $values, bool $isTesting = false): self
    {
        return new self($values, $isTesting);
    }

    public static function fromLaravel(array $phrases = []): self
    {
        $values = config('classroom.qwen', []);
        if ($phrases !== []) {
            $values['phrases'] = $phrases;
        }
        return new self($values, app()->environment('testing'));
    }

    public function missing(): array
    {
        $missing = [];
        foreach (['api_key' => $this->apiKey, 'workspace_id' => $this->workspaceId, 'region' => $this->region, 'endpoint' => $this->endpoint] as $key => $value) {
            if ($value === '' || preg_match('/^(replace[-_ ]?me|your[-_ ].*|changeme)$/i', $value)) {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    public function validate(): void
    {
        if ($this->missing() !== []) {
            throw new InvalidArgumentException('QWEN_CONFIGURATION_MISSING');
        }
        if (!preg_match('/^qwen3\.8-livetranslate-flash-realtime(?:-[a-z0-9-]+)?$/D', $this->model)) {
            throw new InvalidArgumentException('QWEN_MODEL_PROTOCOL_UNSUPPORTED');
        }
        if (preg_match('/[\r\n]/', $this->apiKey)) {
            throw new InvalidArgumentException('QWEN_CREDENTIAL_INVALID');
        }
        $url = parse_url($this->endpoint);
        if (!is_array($url) || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || isset($url['query'])) {
            throw new InvalidArgumentException('QWEN_ENDPOINT_INVALID');
        }
        $host = strtolower($url['host'] ?? '');
        if ($this->testEndpoint && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) && in_array($url['scheme'] ?? '', ['ws','wss'], true)) {
            return;
        }
        if (!in_array($this->region, ['cn-beijing','ap-southeast-1'], true) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]{0,126}$/D', $this->workspaceId)) {
            throw new InvalidArgumentException('QWEN_REGION_WORKSPACE_INVALID');
        }
        $expectedHost = strtolower($this->workspaceId.'.'.$this->region.'.maas.aliyuncs.com');
        if (($url['scheme'] ?? '') !== 'wss' || $host !== $expectedHost || ($url['path'] ?? '') !== '/api-ws/v1/realtime' || (isset($url['port']) && $url['port'] !== 443)) {
            throw new InvalidArgumentException('QWEN_ENDPOINT_REGION_WORKSPACE_MISMATCH');
        }
    }

    public function connectionUrl(): string
    {
        $this->validate();
        return $this->endpoint.'?model='.rawurlencode($this->model);
    }

    public function sessionUpdate(string $language): array
    {
        if (!in_array($language, self::LANGUAGES, true)) {
            throw new InvalidArgumentException('QWEN_TARGET_LANGUAGE_UNSUPPORTED');
        }
        // These are local resource caps, not a claim that the provider accepts these maxima.
        if (count($this->phrases) > 1000) {
            throw new InvalidArgumentException('QWEN_LOCAL_PHRASES_LIMIT');
        }
        $translation = ['language' => $language];
        if ($this->phrases !== []) {
            foreach ($this->phrases as $source => $target) {
                $source = (string) $source;
                if (!is_string($target) || !mb_check_encoding($source, 'UTF-8') || !mb_check_encoding($target, 'UTF-8') || trim($source) === '' || trim($target) === '' || strlen($source) > self::MAX_PHRASE_SOURCE_BYTES || strlen($target) > self::MAX_PHRASE_TARGET_BYTES || preg_match('/[\x00-\x1f\x7f]/u', $source.$target)) {
                    throw new InvalidArgumentException('QWEN_LOCAL_PHRASE_INVALID');
                }
            }
            $translation['corpus'] = ['phrases' => (object) $this->phrases];
        }
        // 3.8 ASR is always enabled; do not copy 3.5 transcription or modality settings.
        $update = ['type' => 'session.update', 'session' => ['output_modalities' => ['text'], 'translation' => $translation]];
        if (self::updateBytes($update) > self::MAX_SESSION_UPDATE_BYTES) {
            throw new InvalidArgumentException('QWEN_LOCAL_PAYLOAD_LIMIT');
        }
        return $update;
    }

    public static function updateBytes(array $update): int
    {
        $update['event_id'] = 'evt_'.str_repeat('0', 24);
        return strlen(json_encode($update, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
