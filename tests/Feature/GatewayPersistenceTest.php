<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Realtime\Gateway;
use App\Translation\QwenEvents;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

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

    private function unit(string $id, string $kind, string $item, string $text): array
    {
        return ['type'=>'subtitle.upsert','unit_id'=>$id,'kind'=>$kind,'item_id'=>$item,'text'=>$text,'status'=>'completed','provider_event_id'=>'shared-provider-event'];
    }

    private function persist(array $event): void
    {
        (new \ReflectionMethod(Gateway::class, 'modelEvent'))->invoke($this->gateway, $this->lesson, $this->modelSession, 16000, $event);
    }
}
