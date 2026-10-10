<?php

declare(strict_types=1);

namespace App\Translation;

use UnexpectedValueException;

/** Stateful normalization for one upstream session; no text generation or fallback. */
final class QwenEvents
{
    private array $seen = [];
    private array $units = [];
    private array $relations = [];
    private array $times = [];
    private array $responses = [];
    private array $speechPending = [];
    private int $sequence = 0;
    private const TERMINAL = ['completed', 'failed', 'incomplete', 'interrupted'];

    public function __construct(private readonly ?string $targetLanguage = null) {}

    /** @return list<array> Each row is independent; provider event IDs may repeat across rows. */
    public function normalize(array $event): array
    {
        $type = $event['type'] ?? '';
        if (!is_string($type)) {
            throw new UnexpectedValueException('QWEN_EVENT_TYPE_INVALID');
        }
        $id = $event['event_id'] ?? null;
        if ($id !== null && (!is_string($id) || strlen($id) > 256)) {
            throw new UnexpectedValueException('QWEN_EVENT_ID_INVALID');
        }
        if ($id !== null) {
            if (isset($this->seen[$id])) {
                return [];
            }
            $this->seen[$id] = true;
            if (count($this->seen) > 16384) {
                array_shift($this->seen);
            }
        }
        $out = [];
        switch ($type) {
            case 'conversation.item.created':
                $item = $event['item'] ?? [];
                $itemId = $this->id($item['id'] ?? null);
                $previous = $event['previous_item_id'] ?? null;
                if (($item['role'] ?? null) === 'assistant' && is_string($previous) && $previous !== '') {
                    $this->relations[$itemId] = $this->id($previous);
                    foreach ($this->units as $key => $unit) {
                        if ($unit['kind'] === 'translation' && $unit['item_id'] === $itemId) {
                            $out[] = $this->emit($key, $id);
                        }
                    }
                }
                break;
            case 'input_audio_buffer.speech_started':
            case 'input_audio_buffer.speech_stopped':
                $itemId = $this->id($event['item_id'] ?? null);
                $field = $type === 'input_audio_buffer.speech_started' ? 'start_ms' : 'end_ms';
                $value = $event[$field === 'start_ms' ? 'audio_start_ms' : 'audio_end_ms'] ?? null;
                if (!is_int($value) || $value < 0) {
                    throw new UnexpectedValueException('QWEN_AUDIO_OFFSET_INVALID');
                }
                $this->times[$itemId][$field] = $value;
                $settled = false;
                foreach ($this->units as $unit) {
                    if ($unit['kind'] === 'source' && $unit['item_id'] === $itemId && in_array($unit['status'], self::TERMINAL, true)) $settled = true;
                }
                if (!$settled) $this->speechPending[$itemId] = true;
                foreach ($this->units as $key => $unit) {
                    if (($unit['kind'] === 'source' && $unit['item_id'] === $itemId) || ($unit['kind'] === 'translation' && ($this->relations[$unit['item_id']] ?? null) === $itemId)) {
                        $out[] = $this->emit($key, $id);
                    }
                }
                break;
            case 'conversation.item.input_audio_transcription.delta':
            case 'conversation.item.input_audio_transcription.completed':
            case 'conversation.item.input_audio_transcription.failed':
                $key = $this->ensure('source', $event);
                if ($type === 'conversation.item.input_audio_transcription.delta') {
                    if (!in_array($this->units[$key]['status'], self::TERMINAL, true)) {
                        $this->append($key, $event['delta'] ?? null);
                    }
                } elseif ($type === 'conversation.item.input_audio_transcription.completed') {
                    $this->replace($key, $event['transcript'] ?? null);
                    $this->units[$key]['status'] = 'completed';
                    $this->units[$key]['language'] = is_string($event['language'] ?? null) ? $event['language'] : null;
                    unset($this->speechPending[$this->units[$key]['item_id']]);
                } else {
                    $this->units[$key]['status'] = 'failed';
                    $this->units[$key]['error_code'] = self::safeCode($event['error']['code'] ?? 'transcription_failed');
                    unset($this->speechPending[$this->units[$key]['item_id']]);
                }
                $out[] = $this->emit($key, $id);
                break;
            case 'response.text.delta':
            case 'response.text.done':
                $key = $this->ensure('translation', $event);
                $responseId = $this->id($event['response_id'] ?? null);
                $this->units[$key]['response_id'] = $responseId;
                if ($this->units[$key]['status'] === 'streaming' && isset($this->responses[$responseId])) {
                    $this->units[$key]['status'] = $this->terminalStatus($this->responses[$responseId]['status']) ?? 'streaming';
                }
                if ($type === 'response.text.delta') {
                    if ($this->units[$key]['status'] === 'streaming') {
                        $this->append($key, $event['delta'] ?? null);
                    }
                } else {
                    $this->replace($key, $event['text'] ?? null);
                    // text.done also occurs on failed/interrupted output; response.done is authoritative.
                    $this->units[$key]['status'] = $this->terminalStatus($this->responses[$responseId]['status'] ?? null) ?? 'text_done';
                }
                $out[] = $this->emit($key, $id);
                break;
            case 'response.output_item.added':
                $item = $event['item'] ?? [];
                if (($item['role'] ?? 'assistant') === 'assistant') {
                    $key = $this->ensure('translation', ['item_id' => $item['id'] ?? null, 'content_index' => 0]);
                    $this->units[$key]['response_id'] = $this->id($event['response_id'] ?? null);
                }
                break;
            case 'response.created':
                $response = $event['response'] ?? [];
                $responseId = $this->id($response['id'] ?? null);
                if (!isset($this->responses[$responseId]['status'])) {
                    $this->responses[$responseId] = ['status' => 'in_progress'];
                }
                $out[] = ['type' => 'response.status', 'response_id' => $responseId, 'status' => $this->responses[$responseId]['status'], 'provider_event_id' => $id];
                break;
            case 'response.done':
                $response = $event['response'] ?? [];
                $responseId = $this->id($response['id'] ?? null);
                $status = $this->terminalStatus($response['status'] ?? null) ?? 'incomplete';
                $this->responses[$responseId] = ['status' => $status];
                $output = $response['output'] ?? [];
                if (isset($output['id'])) {
                    $output = [$output];
                }
                foreach ($output as $item) {
                    if (!is_array($item) || ($item['role'] ?? 'assistant') !== 'assistant') {
                        continue;
                    }
                    foreach (($item['content'] ?? []) as $index => $content) {
                        if (($content['type'] ?? '') !== 'text' || !isset($content['text'])) {
                            continue;
                        }
                        $key = $this->ensure('translation', ['item_id' => $item['id'] ?? null, 'content_index' => $index]);
                        $this->units[$key]['response_id'] = $responseId;
                        $this->replace($key, $content['text']);
                        // A response can complete while an individual output item is incomplete.
                        $itemStatus = $this->terminalStatus($item['status'] ?? null);
                        $this->units[$key]['status'] = $status === 'completed' ? ($itemStatus ?? $status) : $status;
                    }
                }
                foreach ($this->units as $key => $unit) {
                    if ($unit['kind'] === 'translation' && $unit['response_id'] === $responseId) {
                        if ($unit['status'] !== 'failed' && $unit['status'] !== 'incomplete' && $unit['status'] !== 'interrupted') {
                            $this->units[$key]['status'] = $status;
                        }
                        $out[] = $this->emit($key, $id);
                    }
                }
                $out[] = ['type' => 'response.status', 'response_id' => $responseId, 'status' => $status, 'provider_event_id' => $id];
                $out[] = ['type' => 'usage', 'response_id' => $responseId, 'status' => $status, 'usage' => is_array($response['usage'] ?? null) ? $response['usage'] : null, 'official' => isset($response['usage']), 'provider_event_id' => $id];
                break;
            case 'session.created':
            case 'session.updated':
            case 'session.finished':
                $out[] = ['type' => 'session.state', 'state' => substr($type, 8), 'session_id' => $event['session']['id'] ?? null, 'provider_event_id' => $id];
                break;
            case 'error':
                $code = self::safeCode($event['error']['code'] ?? $event['code'] ?? 'provider_error');
                $out[] = ['type' => 'upstream.error', 'code' => $code, 'category' => self::errorCategory($code), 'provider_event_id' => $id];
                break;
        }
        $this->boundState();
        return $out;
    }

    /** Mark unfinished text honestly when upstream fails or finish times out. */
    public function interrupt(string $reason): array
    {
        $out = [];
        foreach ($this->units as $key => $unit) {
            if (!in_array($unit['status'], self::TERMINAL, true)) {
                $this->units[$key]['status'] = 'interrupted';
                $this->units[$key]['error_code'] = self::safeCode($reason);
                $out[] = $this->emit($key, null);
            }
        }
        return $out;
    }

    /** Only received provider events are known; a quiet result is advisory, not a VAD guarantee. */
    public function hasPendingWork(): bool
    {
        if ($this->speechPending !== []) return true;
        foreach ($this->units as $unit) if (!in_array($unit['status'], self::TERMINAL, true)) return true;
        foreach ($this->responses as $response) if (!in_array($response['status'] ?? '', self::TERMINAL, true)) return true;
        return false;
    }

    private function ensure(string $kind, array $event): string
    {
        $item = $this->id($event['item_id'] ?? null);
        $index = $event['content_index'] ?? 0;
        if (!is_int($index) || $index < 0 || $index > 64) {
            throw new UnexpectedValueException('QWEN_CONTENT_INDEX_INVALID');
        }
        $key = $kind.':'.$item.':'.$index;
        $this->units[$key] ??= [
            'unit_id' => $key, 'kind' => $kind, 'item_id' => $item, 'content_index' => $index,
            'response_id' => null, 'text' => '', 'status' => 'streaming',
            'language' => $kind === 'translation' ? $this->targetLanguage : null,
        ];
        return $key;
    }

    private function emit(string $key, ?string $eventId): array
    {
        $unit = $this->units[$key];
        $sourceId = $unit['kind'] === 'source' ? $unit['item_id'] : ($this->relations[$unit['item_id']] ?? null);
        $time = $sourceId !== null ? ($this->times[$sourceId] ?? []) : [];
        $start = $time['start_ms'] ?? null;
        $end = $time['end_ms'] ?? null;
        if ($start !== null && $end !== null && $end < $start) {
            $end = null;
        }
        return $unit + [
            'type' => 'subtitle.upsert', 'source_item_id' => $sourceId,
            'mapping' => $unit['kind'] === 'source' ? 'source_identity' : ($sourceId !== null ? 'explicit_previous_item_id' : 'unavailable'),
            'start_ms' => $start, 'end_ms' => $end,
            'timing_precision' => $start !== null && $end !== null ? 'server_segment' : 'unavailable',
            'provider_event_id' => $eventId, 'sequence' => ++$this->sequence,
        ];
    }

    private function append(string $key, mixed $text): void
    {
        $this->replace($key, $this->units[$key]['text'].$this->text($text));
    }

    private function replace(string $key, mixed $text): void
    {
        $this->units[$key]['text'] = $this->text($text);
    }

    private function text(mixed $text): string
    {
        if (!is_string($text) || strlen($text) > 131072 || !mb_check_encoding($text, 'UTF-8')) {
            throw new UnexpectedValueException('QWEN_TEXT_INVALID_OR_TOO_LARGE');
        }
        return $text;
    }

    private function id(mixed $id): string
    {
        if (!is_string($id) || $id === '' || strlen($id) > 256 || preg_match('/[\x00-\x1f\x7f]/', $id) || !mb_check_encoding($id, 'UTF-8')) {
            throw new UnexpectedValueException('QWEN_ITEM_RESPONSE_ID_INVALID');
        }
        return $id;
    }

    private function terminalStatus(mixed $status): ?string
    {
        return match ($status) {
            'completed', 'failed', 'incomplete' => $status,
            'cancelled', 'canceled', 'interrupted' => 'interrupted',
            default => null,
        };
    }

    public static function safeCode(mixed $code): string
    {
        return is_string($code) && preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $code) ? $code : 'provider_error';
    }

    public static function errorCategory(string $code): string
    {
        $code = strtolower($code);
        return match (true) {
            str_contains($code, 'permission'), str_contains($code, 'forbidden'), str_contains($code, 'accessdenied'), str_contains($code, 'access_denied'), str_contains($code, '403') => 'permission',
            str_contains($code, 'auth'), str_contains($code, 'key'), str_contains($code, '401') => 'authentication',
            str_contains($code, 'quota'), str_contains($code, 'balance'), str_contains($code, 'payment'), str_contains($code, 'arrearage') => 'quota',
            str_contains($code, 'limit'), str_contains($code, '429') => 'rate_limit',
            str_contains($code, 'timeout') => 'timeout',
            str_contains($code, 'invalid'), str_contains($code, 'format') => 'input',
            default => 'upstream',
        };
    }

    private function boundState(): void
    {
        if (count($this->speechPending) > 2048) throw new UnexpectedValueException('QWEN_TOO_MANY_PENDING_AUDIO_ITEMS');
        // The database is the durable source of truth. Keep a bounded late-event window in memory.
        while (count($this->units) > 2048) {
            $removed = false;
            foreach ($this->units as $key => $unit) {
                if (in_array($unit['status'], self::TERMINAL, true)) {
                    unset($this->units[$key]);
                    $removed = true;
                    break;
                }
            }
            if (!$removed) {
                throw new UnexpectedValueException('QWEN_TOO_MANY_UNFINISHED_UNITS');
            }
        }
        while (count($this->responses) > 4096) {
            $removed = false;
            foreach ($this->responses as $id => $response) {
                if (in_array($response['status'] ?? '', self::TERMINAL, true)) { unset($this->responses[$id]); $removed = true; break; }
            }
            if (!$removed) throw new UnexpectedValueException('QWEN_TOO_MANY_PENDING_RESPONSES');
        }
        foreach (['relations', 'times'] as $field) {
            while (count($this->{$field}) > 4096) {
                array_shift($this->{$field});
            }
        }
    }
}
