<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Realtime\Gateway;
use App\Domain\Access;
use App\Translation\{QwenConfig, QwenEvents, QwenSession};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

/** Real PostgreSQL persistence, with explicitly constructed provider events. */
final class GatewayPersistenceTest extends TestCase
{
    use DatabaseTransactions;

    private int $lesson;
    private string $modelSession;
    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertStringContainsString('PostgreSQL', DB::selectOne('SELECT version() AS version')->version);
        $user = DB::table('users')->insertGetId(['name'=>'Gateway fixture','student_id'=>'fixture-'.Str::uuid(),'password'=>'not-used','role'=>'user','must_change_password'=>false]);
        $course = DB::table('courses')->insertGetId(['name'=>'Protocol fixture']);
        $this->lesson = DB::table('lessons')->insertGetId(['course_id'=>$course,'creator_id'=>$user,'name'=>'Protocol fixture','target_language'=>'zh','idempotency_key'=>(string) Str::uuid()]);
        $this->modelSession = (string) Str::uuid();
        DB::table('model_sessions')->insert(['id'=>$this->modelSession,'lesson_id'=>$this->lesson,'base_sample'=>16000,'model'=>'qwen3.8-livetranslate-flash-realtime','config'=>'{}','status'=>'ready']);
        $this->gateway = new Gateway();
    }

    public function test_one_provider_event_can_persist_two_separate_units_and_deduplicates_replays(): void
    {
        $source = $this->unit('source:asr:0', 'source', 'asr', 'hello');
        $translation = $this->unit('translation:tr:0', 'translation', 'tr', '你好') + ['response_id'=>'r','source_item_id'=>'asr','mapping'=>'explicit_previous_item_id'];
        $this->persist($source);
        $this->persist($translation);
        $this->persist($source);
        $this->persist($translation);
        self::assertSame(2, DB::table('subtitle_units')->where('lesson_id',$this->lesson)->count());
        self::assertSame(2, DB::table('lesson_events')->where('lesson_id',$this->lesson)->count());
        $saved = DB::table('subtitle_units')->where('lesson_id',$this->lesson)->where('kind','translation')->first();
        self::assertSame('tr', $saved->item_id);
        self::assertSame('asr', $saved->source_item_id);
        self::assertSame('你好', $saved->original_text);
    }

    public function test_late_provider_metadata_preserves_confirmed_original_and_manual_revision(): void
    {
        $unit = $this->unit('source:asr:0', 'source', 'asr', 'original');
        $this->persist($unit);
        DB::table('subtitle_units')->where('lesson_id',$this->lesson)->update(['current_text'=>'teacher correction','version'=>2]);
        $unit['provider_event_id'] = 'late-time';
        $unit['text'] = 'late provider changed result';
        $unit['start_ms'] = 100;
        $unit['end_ms'] = 500;
        $unit['timing_precision'] = 'server_segment';
        $this->persist($unit);
        $saved = DB::table('subtitle_units')->where('lesson_id',$this->lesson)->first();
        self::assertSame('original', $saved->original_text);
        self::assertSame('teacher correction', $saved->current_text);
        self::assertSame(2, $saved->version);
        self::assertSame(17600, $saved->start_sample);
        self::assertSame(24000, $saved->end_sample);
        self::assertSame('server_segment', $saved->timing_precision);
    }

    public function test_text_done_followed_by_incomplete_response_is_never_persisted_as_complete(): void
    {
        $parser = new QwenEvents('zh');
        foreach ($parser->normalize(['event_id'=>'text','type'=>'response.text.done','item_id'=>'tr','response_id'=>'r','text'=>'unfinished']) as $event) {
            $this->persist($event);
        }
        self::assertSame('text_done', DB::table('subtitle_units')->where('lesson_id',$this->lesson)->value('status'));
        foreach ($parser->normalize(['event_id'=>'done','type'=>'response.done','response'=>['id'=>'r','status'=>'incomplete','output'=>[]]]) as $event) {
            $this->persist($event);
        }
        self::assertSame('incomplete', DB::table('subtitle_units')->where('lesson_id',$this->lesson)->value('status'));
        self::assertSame('unfinished', DB::table('subtitle_units')->where('lesson_id',$this->lesson)->value('original_text'));
        self::assertNull(DB::table('subtitle_units')->where('lesson_id',$this->lesson)->value('start_sample'));
    }

    public function test_short_recorder_disconnect_preserves_epoch_and_only_the_same_login_can_reconnect(): void
    {
        $owner = $this->liveRecorder();
        $token = str_repeat('a', 64);
        $old = $this->connection('old-device');
        $new = $this->connection('same-device-new-socket');
        $other = $this->connection('different-login');
        $clients = new \ReflectionProperty(Gateway::class, 'clients');
        $clients->setValue($this->gateway, [
            'old-device' => ['lesson_id'=>$this->lesson, 'session_id'=>'original-login'],
            'same-device-new-socket' => ['lesson_id'=>$this->lesson, 'session_id'=>'original-login', 'reconnect'=>true],
            'different-login' => ['lesson_id'=>$this->lesson, 'session_id'=>'different-login', 'reconnect'=>true],
        ]);
        (new \ReflectionProperty(Gateway::class, 'recorderReconnect'))->setValue($this->gateway, [$this->lesson=>[
            'key'=>'old-device', 'epoch'=>3, 'session'=>'original-login', 'token'=>$token,
            'until'=>null, 'audio_start'=>1, 'received_frames'=>0,
        ]]);
        (new \ReflectionMethod(Gateway::class, 'disconnect'))->invoke($this->gateway, $old);
        $resume = new \ReflectionMethod(Gateway::class, 'resumeRecorder');
        self::assertSame('live', DB::table('lessons')->find($this->lesson)->status);
        self::assertNull($resume->invoke($this->gateway, $other, $owner, $token));
        self::assertNull($resume->invoke($this->gateway, $new, $owner, str_repeat('b', 64)));
        self::assertSame(['epoch'=>3,'next_sequence'=>12], $resume->invoke($this->gateway, $new, $owner, $token));
        self::assertSame(17, $new->context->receivedFrames);
        self::assertSame('same-device-new-socket', DB::table('lessons')->find($this->lesson)->recorder_connection);
    }

    public function test_failed_upstream_keeps_the_recorder_and_schedules_translation_recovery(): void
    {
        $this->liveRecorder();
        $config = new QwenConfig(['api_key'=>'test-key-not-real','workspace_id'=>'test','region'=>'cn-beijing',
            'endpoint'=>'ws://127.0.0.1:9','allow_test_endpoint'=>true], true);
        $context = (object)['base'=>16000,'key'=>'old-device','epoch'=>3];
        $peer = new QwenSession($config, 'zh', fn () => null, fn () => null);
        (new \ReflectionProperty(Gateway::class, 'upstreams'))->setValue($this->gateway, [$this->lesson=>[
            'sid'=>$this->modelSession,'cfg'=>$config,'language'=>'zh','context'=>$context,'peer'=>$peer,'meta'=>[],
        ]]);
        (new \ReflectionMethod(Gateway::class, 'modelState'))->invoke($this->gateway, $this->lesson, $this->modelSession, 'failed', $context);
        $lesson = DB::table('lessons')->find($this->lesson);
        self::assertSame('live', $lesson->status);
        self::assertSame('recovering', $lesson->translation_status);
        self::assertSame('old-device', $lesson->recorder_connection);
        self::assertSame(3, $lesson->recorder_epoch);
        self::assertSame(1, DB::table('recording_issues')->where('lesson_id',$this->lesson)->count());
        self::assertArrayHasKey($this->lesson, (new \ReflectionProperty(Gateway::class, 'recovering'))->getValue($this->gateway));
    }

    private function liveRecorder(): object
    {
        $owner = Access::user((int)DB::table('lessons')->find($this->lesson)->creator_id);
        DB::table('lessons')->where('id',$this->lesson)->update([
            'recorder_user_id'=>$owner->id, 'recorder_connection'=>'old-device', 'recorder_epoch'=>3,
            'status'=>'live', 'lease_until'=>now()->addSeconds(20), 'media_samples'=>19200, 'next_sequence'=>12,
        ]);
        return $owner;
    }

    private function connection(string $key): TcpConnection
    {
        $connection = (new \ReflectionClass(TcpConnection::class))->newInstanceWithoutConstructor();
        $connection->context = (object)['key'=>$key,'authorized'=>true,'audioStart'=>microtime(true)-2,'receivedFrames'=>17];
        return $connection;
    }

    private function unit(string $id, string $kind, string $item, string $text): array
    {
        return ['type'=>'subtitle.upsert','unit_id'=>$id,'kind'=>$kind,'item_id'=>$item,'text'=>$text,'status'=>'completed','provider_event_id'=>'shared-provider-event'];
    }

    private function persist(array $event): void
    {
        (new \ReflectionMethod(Gateway::class, 'modelEvent'))->invoke($this->gateway, $this->lesson, $this->modelSession, 16000, $event);
    }
}
