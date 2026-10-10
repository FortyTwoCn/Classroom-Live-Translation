<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Content\{SubtitleService,SubtitleText};
use App\Domain\{Access,Failure};
use App\Exports\ExportService;
use App\Realtime\Gateway;
use App\Translation\QwenEvents;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{DB,Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

/** Real PostgreSQL/API regression; provider messages below are explicitly fixtures. */
final class EmptySubtitleTest extends TestCase
{
    use DatabaseTransactions;

    private int $lesson;
    private int $course;
    private string $model;
    private object $admin;
    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('pgsql',DB::connection()->getDriverName());
        Queue::fake();
        $uid=DB::table('users')->insertGetId(['student_id'=>'empty-'.Str::uuid(),'name'=>'Empty subtitle fixture','password'=>'unused','role'=>'admin','must_change_password'=>false]);
        $this->admin=Access::user($uid);
        $this->course=DB::table('courses')->insertGetId(['name'=>'Empty subtitle fixture','guest_access'=>'public']);
        $this->lesson=DB::table('lessons')->insertGetId(['course_id'=>$this->course,'creator_id'=>$uid,'name'=>'Empty subtitle fixture','status'=>'ended','target_language'=>'zh','idempotency_key'=>(string)Str::uuid()]);
        $this->model=(string)Str::uuid();
        DB::table('model_sessions')->insert(['id'=>$this->model,'lesson_id'=>$this->lesson,'base_sample'=>0,'model'=>'explicit-fixture','config'=>'{}','status'=>'finished']);
        $this->gateway=new Gateway();
    }

    public function test_empty_provider_events_create_no_caption_or_shared_caption_event(): void
    {
        $parser=new QwenEvents('zh');
        foreach([''," \t\r\n","\u{00A0}\u{3000}","\u{200B}\u{200F}\u{FEFF}"] as $index=>$blank){
            foreach($parser->normalize(['type'=>'conversation.item.input_audio_transcription.completed','event_id'=>'source-'.$index,'item_id'=>'asr-'.$index,'transcript'=>$blank]) as $event)$this->persist($event);
            foreach($parser->normalize(['type'=>'response.text.done','event_id'=>'translation-'.$index,'response_id'=>'r-'.$index,'item_id'=>'tr-'.$index,'text'=>$blank]) as $event)$this->persist($event);
            foreach($parser->normalize(['type'=>'response.done','event_id'=>'done-'.$index,'response'=>['id'=>'r-'.$index,'status'=>'completed','output'=>[]]]) as $event)$this->persist($event);
        }
        self::assertSame(0,DB::table('subtitle_units')->where('lesson_id',$this->lesson)->count());
        self::assertSame(0,DB::table('lesson_events')->where('lesson_id',$this->lesson)->where('event_type','subtitle.updated')->count());
        $this->persist($this->event('translation','tr-real','有内容的译文'));
        $this->persist($this->event('source','asr-real','0，ЇЄҐ'));
        self::assertSame(2,DB::table('subtitle_units')->where('lesson_id',$this->lesson)->count());
    }

    public function test_blank_final_result_retracts_only_an_unfinished_draft_and_emits_a_tombstone(): void
    {
        $draft=$this->event('source','draft','unconfirmed words','streaming');
        $this->persist($draft);
        $uid=(int)DB::table('subtitle_units')->where('lesson_id',$this->lesson)->value('id');
        $this->persist($this->event('source','draft',"\u{3000}",'completed'));
        self::assertSame(0,DB::table('subtitle_units')->where('lesson_id',$this->lesson)->count());
        $payload=json_decode(DB::table('lesson_events')->where('lesson_id',$this->lesson)->orderByDesc('seq')->value('payload'),true);
        self::assertSame($uid,$payload['unit']['id']);
        self::assertNotEmpty($payload['unit']['deleted_at']);
        self::assertSame('unconfirmed words',$payload['unit']['text']);
        $this->persist($this->event('source','draft','later valid result'));
        self::assertSame('later valid result',DB::table('subtitle_units')->where('lesson_id',$this->lesson)->value('original_text'));
    }

    public function test_blank_late_metadata_preserves_confirmed_text_and_manual_correction(): void
    {
        $this->persist($this->event('source','confirmed','original words'));
        DB::table('subtitle_units')->where('lesson_id',$this->lesson)->update(['current_text'=>'teacher correction','version'=>2]);
        $this->persist($this->event('source','confirmed','')+['start_ms'=>100,'end_ms'=>500,'timing_precision'=>'server_segment']);
        $saved=DB::table('subtitle_units')->where('lesson_id',$this->lesson)->first();
        self::assertSame('original words',$saved->original_text);
        self::assertSame('teacher correction',$saved->current_text);
        self::assertSame(2,$saved->version);
        self::assertSame(1600,$saved->start_sample);
    }

    public function test_legacy_blank_rows_do_not_consume_pages_snapshots_time_lookup_or_admin_totals(): void
    {
        $source=$this->legacy('source','real source');
        $translation=$this->legacy('translation','real translation');
        $corrected=$this->legacy('source','');
        DB::table('subtitle_units')->where('id',$corrected)->update(['current_text'=>'teacher correction','version'=>2]);
        for($i=0;$i<205;$i++)$this->legacy($i%2?'source':'translation'," \u{00A0}\u{3000}\u{200B}");
        $this->getJson('/api/lessons/'.$this->lesson)->assertOk()->assertJsonCount(3,'units');
        $this->getJson('/api/lessons/'.$this->lesson.'/subtitles')->assertOk()->assertJsonCount(3,'units')->assertJsonPath('total',3)->assertJsonPath('has_more',false);
        $this->getJson('/api/lessons/'.$this->lesson.'/subtitles?before_id='.$translation)->assertOk()->assertJsonCount(1,'units')->assertJsonPath('units.0.id',$source);
        $this->getJson('/api/lessons/'.$this->lesson.'/subtitles?at_sample=1000')->assertOk()->assertJsonCount(3,'units');
        $this->getJson('/api/courses/'.$this->course.'/subtitles?q=e')->assertOk()->assertJsonPath('total',3);
        $list=app(SubtitleService::class)->adminList($this->admin,['lesson_id'=>$this->lesson]);
        self::assertSame(3,$list['total']);
        // Existing evidence stays in PostgreSQL; this repair does not purge old data.
        self::assertSame(208,DB::table('subtitle_units')->where('lesson_id',$this->lesson)->count());
    }

    public function test_unicode_blank_text_cannot_be_saved_by_manual_revision(): void
    {
        $uid=$this->legacy('source','words');
        $this->expectException(Failure::class);
        $this->expectExceptionMessage('subtitle_text_invalid');
        app(SubtitleService::class)->revise($this->lesson,$this->admin,[['unit_id'=>$uid,'expected_version'=>1,'text'=>"\u{200B}\u{3000}"]],(string)Str::uuid());
    }

    public function test_exports_skip_blank_source_but_keep_independent_nonempty_translation(): void
    {
        $this->legacy('source',"\u{200B}\u{3000}");
        $options=['format'=>'txt','layout'=>'bilingual','text_version'=>'current','idempotency_key'=>(string)Str::uuid()];
        try{app(ExportService::class)->create($this->lesson,$this->admin,$options);self::fail('Blank-only export must be rejected');}catch(Failure $e){self::assertSame('export_no_content',$e->getMessage());}
        $this->legacy('translation','Independent translation');
        foreach(['current','original'] as $version){
            $options['text_version']=$version;$options['idempotency_key']=(string)Str::uuid();
            $export=app(ExportService::class)->create($this->lesson,$this->admin,$options);
            self::assertSame(1,$export['snapshot_count']);
            $row=DB::table('subtitle_export_rows')->where('export_id',$export['id'])->first();
            self::assertSame('Independent translation',json_decode($row->payload,true)['text']);
        }
    }

    public function test_php_and_postgresql_agree_on_blank_and_visible_unicode_text(): void
    {
        foreach(['',"\t\n\r ","\u{00A0}\u{3000}","\u{180E}\u{200B}\u{200F}\u{FEFF}","\u{202E}\u{2060}",'0','，','中文','ЇЄҐ',' hello '] as $text){
            $sql=DB::selectOne('SELECT CAST(? AS text) ~ CAST(? AS text) AS visible',[$text,SubtitleText::NON_BLANK]);
            self::assertSame(SubtitleText::hasContent($text),(bool)$sql->visible);
        }
    }

    private function event(string $kind,string $item,string $text,string $status='completed'): array
    {
        return ['type'=>'subtitle.upsert','unit_id'=>$kind.':'.$item.':0','kind'=>$kind,'item_id'=>$item,'text'=>$text,'status'=>$status,'provider_event_id'=>(string)Str::uuid()];
    }

    private function persist(array $event): void
    {
        if(($event['type']??'')!=='subtitle.upsert')return;
        (new \ReflectionMethod(Gateway::class,'modelEvent'))->invoke($this->gateway,$this->lesson,$this->model,0,$event);
    }

    private function legacy(string $kind,string $text): int
    {
        return DB::table('subtitle_units')->insertGetId(['lesson_id'=>$this->lesson,'model_session_id'=>$this->model,'provider_unit_id'=>(string)Str::uuid(),'kind'=>$kind,'original_text'=>$text,'status'=>'completed','start_sample'=>0,'end_sample'=>16000,'timing_precision'=>'server_segment']);
    }
}
