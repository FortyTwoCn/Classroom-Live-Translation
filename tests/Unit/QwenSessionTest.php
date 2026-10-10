<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Translation\QwenConfig;
use App\Translation\QwenSession;
use App\Translation\QwenWebSocket;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Events\Select;
use Workerman\Timer;
use Workerman\Worker;

/** Simulated upstream transport. These tests never contact Alibaba Cloud. */
final class QwenSessionTest extends TestCase
{
    private Select $loop;
    private Worker $server;
    private array $connections = [];
    private ?QwenSession $session = null;
    private ?\Workerman\Events\EventInterface $previousLoop;
    private string $endpoint;

    protected function setUp(): void
    {
        $this->previousLoop = Worker::$globalEvent;
        $this->loop = new Select();
        Worker::$globalEvent = $this->loop;
        Timer::init($this->loop);
        $this->loop->setErrorHandler(static function (\Throwable $error): void { throw $error; });
        $this->server = new Worker('websocket://127.0.0.1:0');
        $this->server->onConnect = function (TcpConnection $connection): void { $this->connections[] = $connection; };
        $this->server->onWebSocketConnect = static function (TcpConnection $connection): void {
            $connection->send(json_encode(['event_id'=>'created','type'=>'session.created','session'=>['id'=>'session_test','model'=>'qwen3.8-livetranslate-flash-realtime']]));
        };
        $this->server->listen();
        $address = stream_socket_get_name($this->server->getMainSocket(), false);
        $this->endpoint = 'ws://'.$address.'/api-ws/v1/realtime';
    }

    protected function tearDown(): void
    {
        $this->session?->close();
        foreach ($this->connections as $connection) {
            $connection->destroy();
        }
        $this->server->unlisten();
        $this->loop->stop();
        Worker::$globalEvent = $this->previousLoop;
        Timer::init($this->previousLoop);
    }

    public function test_audio_is_gated_by_session_updated_and_finish_preserves_tail_status(): void
    {
        $commands = [];
        $events = [];
        $states = [];
        $this->server->onMessage = static function (TcpConnection $connection, string $json) use (&$commands): void {
            $command = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $commands[] = $command;
            if ($command['type'] === 'session.update') {
                self::assertSame(['text'], $command['session']['output_modalities']);
                self::assertSame(['language'=>'zh'], $command['session']['translation']);
                $connection->send(json_encode(['event_id'=>'updated','type'=>'session.updated','session'=>$command['session']]));
            } elseif ($command['type'] === 'session.finish') {
                $connection->send(json_encode(['event_id'=>'source_final','type'=>'conversation.item.input_audio_transcription.completed','item_id'=>'source_tail','transcript'=>'tail utterance','language'=>'en']));
                $connection->send(json_encode(['event_id'=>'text_done','type'=>'response.text.done','response_id'=>'r','item_id'=>'translation_tail','text'=>'尾句']));
                $connection->send(json_encode(['event_id'=>'response_done','type'=>'response.done','response'=>['id'=>'r','status'=>'incomplete','output'=>[]]]));
                $connection->send(json_encode(['event_id'=>'finished','type'=>'session.finished']));
            }
        };
        $this->session = new QwenSession($this->config(), 'zh', static function (array $event) use (&$events): void { $events[] = $event; }, function (string $state) use (&$states): void {
            $states[] = $state;
            if ($state === 'ready') {
                $this->session->sendAudio(str_repeat("\x00", 3200));
                $this->session->finish();
            } elseif (in_array($state, ['failed','finished'], true)) {
                Timer::add(0.01, fn () => $this->loop->stop(), [], false);
            }
        });
        $this->session->connect();
        self::assertFalse($this->session->isReady());
        try {
            $this->session->sendAudio("\x00\x00");
            self::fail('Audio was accepted before session.updated.');
        } catch (\LogicException $error) {
            self::assertSame('QWEN_SESSION_NOT_READY', $error->getMessage());
        }
        Timer::add(3, fn () => $this->loop->stop(), [], false);
        $this->loop->run();
        self::assertSame(['session.update','input_audio_buffer.append','session.finish'], array_column($commands, 'type'));
        self::assertContains('finished', $states);
        self::assertNotContains('failed', $states);
        $subtitles = array_values(array_filter($events, static fn ($event) => $event['type'] === 'subtitle.upsert'));
        self::assertSame('completed', $subtitles[0]['status']);
        self::assertSame('incomplete', $subtitles[count($subtitles)-1]['status']);
        self::assertSame(1600, $this->session->sentSamples());
    }

    public function test_finish_timeout_is_bounded_and_keeps_partial_translation_interrupted(): void
    {
        $events = [];
        $failure = null;
        $this->server->onMessage = static function (TcpConnection $connection, string $json): void {
            $command = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if ($command['type'] === 'session.update') {
                $connection->send(json_encode(['event_id'=>'updated','type'=>'session.updated','session'=>$command['session']]));
            } elseif ($command['type'] === 'session.finish') {
                $connection->send(json_encode(['event_id'=>'partial','type'=>'response.text.delta','response_id'=>'r','item_id'=>'tr','delta'=>'unfinished']));
                // Intentionally withhold response.done and session.finished.
            }
        };
        $this->session = new QwenSession($this->config(), 'zh', static function (array $event) use (&$events): void { $events[] = $event; }, function (string $state, array $details) use (&$failure): void {
            if ($state === 'ready') {
                $this->session->finish();
            } elseif ($state === 'failed') {
                $failure = $details;
                Timer::add(0.01, fn () => $this->loop->stop(), [], false);
            }
        });
        $started = microtime(true);
        $this->session->connect();
        Timer::add(3, fn () => $this->loop->stop(), [], false);
        $this->loop->run();
        self::assertLessThan(2.5, microtime(true) - $started);
        self::assertSame('session_finish_timeout', $failure['code']);
        $subtitles = array_values(array_filter($events, static fn ($event) => $event['type'] === 'subtitle.upsert'));
        self::assertSame('interrupted', $subtitles[count($subtitles)-1]['status']);
        self::assertSame('unfinished', $subtitles[count($subtitles)-1]['text']);
        self::assertFalse($this->session->isReady());
    }

    public function test_business_callback_failure_is_redacted_and_notifies_failure(): void
    {
        $failure = null;
        $this->session = new QwenSession($this->config(), 'zh', static function (): void {
            throw new \RuntimeException('SQL bindings include private subtitle and secret credential');
        }, static function (string $state, array $details) use (&$failure): void {
            if ($state === 'failed') $failure = $details;
        });
        try {
            (new \ReflectionMethod(QwenSession::class, 'receive'))->invoke($this->session, json_encode([
                'type'=>'conversation.item.input_audio_transcription.completed','item_id'=>'asr','transcript'=>'private text',
            ], JSON_THROW_ON_ERROR));
            self::fail('Business failure was silently ignored.');
        } catch (\LogicException $error) {
            self::assertSame('QWEN_BUSINESS_CALLBACK_FAILED', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertSame(['code'=>'business_callback_failed','category'=>'internal'], $failure);
        self::assertFalse($this->session->isReady());
    }

    public function test_malformed_handshake_is_rejected_before_raw_header_diagnostics(): void
    {
        $connection = new AsyncTcpConnection($this->endpoint);
        $connection->context->handshakeStep = 1;
        $connection->context->websocketSecKey = 'test-only-websocket-key';
        $errors = [];
        $connection->onError = static function ($connection, int $code, string $message) use (&$errors): void {
            $errors[] = [$code, $message];
        };
        $result = QwenWebSocket::input("HTTP/1.1 101 Switching Protocols\r\nSec-WebSocket-Accept: incorrect\r\nSensitive-Echo: private-value\r\n\r\n", $connection);
        self::assertSame(0, $result);
        self::assertSame([[400,'upstream_http_handshake_rejected']], $errors);
        self::assertStringNotContainsString('private-value', json_encode($errors));
    }

    public function test_rotation_observation_tracks_ready_audio_count_and_pending_provider_work():void
    {
        $checks=[];
        $this->server->onMessage=static function(TcpConnection $connection,string $json):void{
            $command=json_decode($json,true);
            if($command['type']==='session.update')$connection->send(json_encode(['type'=>'session.updated','session'=>$command['session']]));
            elseif($command['type']==='input_audio_buffer.append'){
                $connection->send(json_encode(['type'=>'input_audio_buffer.speech_started','item_id'=>'s','audio_start_ms'=>0]));
                $connection->send(json_encode(['type'=>'conversation.item.input_audio_transcription.delta','item_id'=>'s','delta'=>'air']));
                $connection->send(json_encode(['type'=>'conversation.item.input_audio_transcription.completed','item_id'=>'s','transcript'=>'airfoil']));
                $connection->send(json_encode(['type'=>'input_audio_buffer.speech_stopped','item_id'=>'s','audio_end_ms'=>100]));
            }elseif($command['type']==='session.finish')$connection->send(json_encode(['type'=>'session.finished']));
        };
        $this->session=new QwenSession($this->config(),'zh',function(array $event)use(&$checks):void{
            if(($event['type']??'')==='subtitle.upsert'){
                $checks[$event['status']]=$this->session->canRotateAtBoundary();
                if($event['status']==='completed')Timer::add(0.01,function():void{$this->session->finish();self::assertFalse($this->session->canRotateAtBoundary());},[],false);
            }
        },function(string $state)use(&$checks):void{
            if($state==='ready'){$checks['ready']=$this->session->canRotateAtBoundary();$this->session->sendAudio(str_repeat("\0",3200));}
            if($state==='finished')$this->loop->stop();
        });
        self::assertFalse($this->session->canRotateAtBoundary());self::assertSame(0.0,$this->session->elapsedSeconds());$this->session->connect();
        Timer::add(3,fn()=>$this->loop->stop(),[],false);$this->loop->run();
        self::assertTrue($checks['ready']);self::assertFalse($checks['streaming']);self::assertTrue($checks['completed']);
        self::assertSame(1600,$this->session->sentSamplesCount());self::assertGreaterThan(0,$this->session->elapsedSeconds());self::assertFalse($this->session->canRotateAtBoundary());
    }

    public function test_phrase_echo_mismatch_fails_before_audio_is_allowed():void
    {
        $failure=null;$commands=[];
        $this->server->onMessage=static function(TcpConnection $connection,string $json)use(&$commands):void{
            $command=json_decode($json,true);$commands[]=$command['type'];$session=$command['session'];unset($session['translation']['corpus']);$connection->send(json_encode(['type'=>'session.updated','session'=>$session]));
        };
        $config=QwenConfig::fromArray(['api_key'=>'test-only','workspace_id'=>'test','region'=>'cn-beijing','endpoint'=>$this->endpoint,'allow_test_endpoint'=>true,'phrases'=>['airfoil'=>'翼型']],true);
        $this->session=new QwenSession($config,'zh',static function():void{},function(string $state,array $details)use(&$failure):void{if($state==='failed'){$failure=$details;$this->loop->stop();}});
        $this->session->connect();Timer::add(3,fn()=>$this->loop->stop(),[],false);$this->loop->run();
        self::assertSame('provider_phrases_configuration_mismatch',$failure['code']);self::assertSame(['session.update'],$commands);self::assertFalse($this->session->isReady());self::assertSame(0,$this->session->sentSamplesCount());
    }

    private function config(): QwenConfig
    {
        return QwenConfig::fromArray(['api_key'=>'test-key-not-real','workspace_id'=>'test','region'=>'cn-beijing','endpoint'=>$this->endpoint,'allow_test_endpoint'=>true,'finish_timeout'=>1,'connect_timeout'=>1], true);
    }
}
