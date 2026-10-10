<?php

declare(strict_types=1);

namespace App\Translation;

use Closure;
use JsonException;
use LogicException;
use Throwable;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Protocols\Ws;
use Workerman\Timer;

/** One genuine provider session, running inside the gateway's Workerman event loop. */
final class QwenSession
{
    private ?AsyncTcpConnection $connection = null;
    private ?int $deadline = null;
    private string $state = 'idle';
    private bool $updateSent = false;
    private int $sentSamples = 0;
    private readonly Closure $onEvent;
    private readonly Closure $onState;
    private readonly QwenEvents $events;
    private readonly array $update;
    private ?string $sessionId = null;
    private array $controlEvents = [];
    private ?float $connectedAt = null;

    public function __construct(private readonly QwenConfig $config, private readonly string $language, callable $onEvent, callable $onState)
    {
        $config->validate();
        $this->update = $config->sessionUpdate($language);
        $this->onEvent = Closure::fromCallable($onEvent);
        $this->onState = Closure::fromCallable($onState);
        $this->events = new QwenEvents($language);
    }

    public function connect(): void
    {
        if ($this->state !== 'idle') {
            throw new LogicException('QWEN_SESSION_ALREADY_STARTED');
        }
        $url = parse_url($this->config->connectionUrl());
        $host = $url['host'];
        $tls = $url['scheme'] === 'wss';
        $address = 'ws://'.$host.':'.($url['port'] ?? ($tls ? 443 : 80)).($url['path'] ?? '/').'?'.$url['query'];
        $connection = new AsyncTcpConnection($address, ['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'peer_name' => $host, 'SNI_enabled' => true,
        ]]);
        $this->connection = $connection;
        $connection->protocol = QwenWebSocket::class;
        if ($tls) {
            $connection->transport = 'ssl';
        }
        $connection->headers = ['Authorization' => 'Bearer '.$this->config->apiKey];
        $connection->websocketType = Ws::BINARY_TYPE_BLOB;
        $connection->websocketPingInterval = 20;
        $connection->maxSendBufferSize = 262144;
        $connection->maxPackageSize = 262144;
        $connection->onWebSocketConnect = function (): void {
            $this->transition('configuring');
            // Wait for session.created before sending configuration, as in the 3.8 example.
        };
        $connection->onMessage = function (AsyncTcpConnection $connection, string $message): void {
            $this->receive($message);
        };
        $connection->onBufferFull = function (): void {
            $this->fail('upstream_backpressure');
        };
        $connection->onError = function (AsyncTcpConnection $connection, int $code, string $message): void {
            // Never forward transport messages: a failed handshake may contain sensitive headers.
            $this->fail(in_array($code, [401,403,429], true) ? 'http_'.$code : 'transport_error', ['transport_code' => $code]);
        };
        $connection->onClose = function (): void {
            $this->cancelDeadline();
            $this->connection = null;
            if (!in_array($this->state, ['closed','finished','failed'], true)) {
                $this->fail('unexpected_close');
            }
        };
        $this->transition('connecting');
        $this->connectedAt = hrtime(true) / 1_000_000_000;
        $this->deadline = Timer::add($this->config->connectTimeout, function (): void {
            $this->fail('connect_configuration_timeout');
        }, [], false);
        $connection->connect();
    }

    public function isReady(): bool
    {
        return $this->state === 'ready' && $this->connection !== null;
    }

    public function sendAudio(string $pcm): void
    {
        if (!$this->isReady()) {
            throw new LogicException('QWEN_SESSION_NOT_READY');
        }
        $size = strlen($pcm);
        if ($size === 0 || $size > 16000 || ($size % 2) !== 0) {
            throw new LogicException('QWEN_PCM_FRAME_INVALID');
        }
        $this->send(['type' => 'input_audio_buffer.append', 'audio' => base64_encode($pcm)]);
        $this->sentSamples += intdiv($size, 2);
    }

    /** Caller first stops capture. Tail processing is asynchronous and bounded. */
    public function finish(): void
    {
        if (in_array($this->state, ['finishing','finished','failed','closed'], true)) {
            return;
        }
        if (!$this->isReady()) {
            $this->fail('finish_before_ready');
            return;
        }
        $this->transition('finishing');
        $this->send(['type' => 'session.finish']);
        $this->cancelDeadline();
        $this->deadline = Timer::add($this->config->finishTimeout, function (): void {
            $this->fail('session_finish_timeout');
        }, [], false);
    }

    /** Immediate emergency release; normal pause/end must call finish() and await terminal state. */
    public function close(): void
    {
        $this->cancelDeadline();
        if (!in_array($this->state, ['finished','failed','closed'], true)) {
            $this->emitInterrupted('caller_closed');
            $this->transition('closed');
        }
        if ($this->connection !== null) {
            $connection = $this->connection;
            $this->connection = null;
            $connection->destroy();
        }
    }

    public function sentSamples(): int
    {
        return $this->sentSamples;
    }

    public function sentSamplesCount(): int { return $this->sentSamples; }

    /** Monotonic elapsed connection time, including setup and tail drain. */
    public function elapsedSeconds(): float
    {
        return $this->connectedAt === null ? 0.0 : max(0.0, hrtime(true) / 1_000_000_000 - $this->connectedAt);
    }

    /** Packet-boundary cutover is owned by Gateway. Provider event arrival can lag audio. */
    public function canRotateAtBoundary(): bool { return $this->isReady() && !$this->events->hasPendingWork(); }

    private function receive(string $message): void
    {
        if (in_array($this->state, ['failed','closed','finished'], true)) {
            return;
        }
        try {
            if (strlen($message) > 262144) {
                $this->fail('provider_event_too_large');
                return;
            }
            $event = json_decode($message, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($event)) {
                $this->fail('provider_event_invalid');
                return;
            }
            $type = $event['type'] ?? '';
            if (in_array($type, ['session.created','session.updated','session.finished'], true) && is_string($event['event_id'] ?? null)) {
                if (isset($this->controlEvents[$event['event_id']])) {
                    return;
                }
                $this->controlEvents[$event['event_id']] = true;
            }
            if ($type === 'session.created') {
                $this->sessionId = is_string($event['session']['id'] ?? null) ? $event['session']['id'] : null;
                if (isset($event['session']['model']) && $event['session']['model'] !== $this->config->model) {
                    $this->fail('provider_model_mismatch');
                    return;
                }
                if (!$this->updateSent) {
                    $this->updateSent = true;
                    $this->send($this->update);
                }
            } elseif ($type === 'session.updated') {
                if (!$this->updateSent || $this->state !== 'configuring') {
                    $this->fail('unexpected_session_updated');
                    return;
                }
                $session = $event['session'] ?? [];
                if (($session['output_modalities'] ?? null) !== ['text'] || ($session['translation']['language'] ?? null) !== $this->language) {
                    $this->fail('provider_text_configuration_mismatch');
                    return;
                }
                $format = $session['audio']['input']['format'] ?? ['type' => 'pcm', 'sample_rate' => 16000];
                if (($format['type'] ?? null) !== 'pcm' || ($format['sample_rate'] ?? null) !== 16000) {
                    $this->fail('provider_input_format_mismatch');
                    return;
                }
                $expectedPhrases = (array) ($this->update['session']['translation']['corpus']['phrases'] ?? []);
                $actualPhrases = $session['translation']['corpus']['phrases'] ?? [];
                if (is_array($actualPhrases)) { ksort($actualPhrases); ksort($expectedPhrases); }
                if (!is_array($actualPhrases) || $actualPhrases !== $expectedPhrases) {
                    $this->fail('provider_phrases_configuration_mismatch');
                    return;
                }
                $this->cancelDeadline();
                $this->transition('ready', ['session_id' => $this->sessionId, 'sample_rate' => 16000, 'channels' => 1, 'bits' => 16, 'phrases_echo_match' => true, 'phrases_count' => count($expectedPhrases)]);
            }
            foreach ($this->events->normalize($event) as $normalized) {
                $this->dispatchEvent($normalized + ['provider_event_type' => $type]);
                if ($normalized['type'] === 'upstream.error') {
                    $this->fail($normalized['code'], ['category' => $normalized['category']]);
                    return;
                }
            }
            if ($type === 'session.finished') {
                if ($this->state !== 'finishing') {
                    $this->fail('unexpected_session_finished');
                    return;
                }
                $this->cancelDeadline();
                // Session end alone cannot promote missing response.done/transcription.completed.
                $this->emitInterrupted('session_finished_without_final_result');
                $this->transition('finished', ['session_id' => $this->sessionId, 'sent_samples' => $this->sentSamples]);
                $this->close();
            }
        } catch (JsonException) {
            $this->fail('provider_json_invalid');
        } catch (\UnexpectedValueException $error) {
            $this->fail(QwenEvents::safeCode($error->getMessage()));
        }
    }

    private function send(array $event): void
    {
        if ($this->connection === null) {
            throw new LogicException('QWEN_CONNECTION_CLOSED');
        }
        $event['event_id'] = 'evt_'.bin2hex(random_bytes(12));
        if ($this->connection->send(json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
            $this->fail('upstream_send_failed');
            throw new LogicException('QWEN_SEND_FAILED');
        }
    }

    private function transition(string $state, array $details = []): void
    {
        if ($this->state === $state) {
            return;
        }
        $this->state = $state;
        try {
            ($this->onState)($state, $details);
        } catch (Throwable) {
            $this->abortBusinessCallback();
        }
    }

    private function fail(string $reason, array $details = []): void
    {
        if (in_array($this->state, ['failed','closed','finished'], true)) {
            return;
        }
        $this->cancelDeadline();
        $this->emitInterrupted($reason);
        $this->transition('failed', ['code' => QwenEvents::safeCode($reason), 'category' => QwenEvents::errorCategory($reason)] + $details);
        $this->close();
    }

    private function emitInterrupted(string $reason): void
    {
        foreach ($this->events->interrupt($reason) as $event) {
            $this->dispatchEvent($event);
        }
    }

    private function dispatchEvent(array $event): void
    {
        try {
            ($this->onEvent)($event);
        } catch (Throwable) {
            $this->abortBusinessCallback();
        }
    }

    private function abortBusinessCallback(): never
    {
        // A database callback failure must immediately stop this upstream's billing and
        // must not place SQL bindings, credentials or private subtitles in Workerman logs.
        $this->cancelDeadline();
        $this->state = 'failed';
        $connection = $this->connection;
        $this->connection = null;
        $connection?->destroy();
        try {
            ($this->onState)('failed', ['code' => 'business_callback_failed', 'category' => 'internal']);
        } catch (Throwable) {
            // Storage can still be unavailable. The gateway recovery path must reconcile it.
        }
        throw new LogicException('QWEN_BUSINESS_CALLBACK_FAILED');
    }

    private function cancelDeadline(): void
    {
        if ($this->deadline !== null) {
            Timer::del($this->deadline);
            $this->deadline = null;
        }
    }
}
