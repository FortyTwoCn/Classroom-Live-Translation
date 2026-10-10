<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\{ArchiveLesson,PurgeLesson};
use App\Realtime\Gateway;
use App\Reliability\Recovery;
use App\Translation\{QwenConfig,QwenSession};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{DB,Queue};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Actual PostgreSQL/callbacks/purge with synthetic late events; no provider connection. */
final class LateModelPurgeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp():void
    {
        parent::setUp();
        self::assertSame('pgsql',DB::connection()->getDriverName());
        Queue::fake();
    }

    public static function purgeStates():array
    {
        return [['queued'],['processing'],['failed'],['purged']];
    }

    #[DataProvider('purgeStates')]
    public function test_late_subtitle_state_finish_and_issue_cannot_write_after_purge_begins(string $purge):void
    {
        $f=$this->fixture();
        $stale=DB::table('lessons')->find($f['lesson']);
        $this->persist($f,$this->subtitle('source:fixture:0','streaming','Initial incomplete source','initial'));
        DB::table('lessons')->where('id',$f['lesson'])->update(['deleted_at'=>now(),'purge_status'=>$purge==='purged'?'queued':$purge]);
        if($purge==='purged'){
            // The real purge removes content before late provider callbacks arrive.
            (new PurgeLesson($f['lesson']))->handle();
            self::assertSame('purged',DB::table('lessons')->where('id',$f['lesson'])->value('purge_status'));
        }
        $unitsBefore=DB::table('subtitle_units')->where('lesson_id',$f['lesson'])->get()->toJson();
        $eventsBefore=DB::table('lesson_events')->where('lesson_id',$f['lesson'])->get()->toJson();
        $issuesBefore=DB::table('recording_issues')->where('lesson_id',$f['lesson'])->get()->toJson();
        $lessonBefore=DB::table('lessons')->find($f['lesson']);

        $this->persist($f,$this->subtitle('source:fixture:0','completed','Late completed source','late-existing'));
        $this->persist($f,$this->subtitle('translation:late:0','completed','Late translated content','late-new','translation'));
        Recovery::interrupted($stale,'translation_uncertain',$f['sid']);

        // Invoke the actual newPeer callback and terminal finalizer, not a mocked service.
        $state=(new \ReflectionProperty(QwenSession::class,'onState'))->getValue($f['peer']);
        $state('ready');
        $state('failed');

        self::assertSame($unitsBefore,DB::table('subtitle_units')->where('lesson_id',$f['lesson'])->get()->toJson());
        self::assertSame($eventsBefore,DB::table('lesson_events')->where('lesson_id',$f['lesson'])->get()->toJson());
        self::assertSame($issuesBefore,DB::table('recording_issues')->where('lesson_id',$f['lesson'])->get()->toJson());
        self::assertEquals($lessonBefore,DB::table('lessons')->find($f['lesson']));
        $session=DB::table('model_sessions')->where('id',$f['sid'])->first();
        self::assertSame('failed',$session->status);
        self::assertNotNull($session->ended_at);
        self::assertSame(1600,json_decode($session->config,true)['sent_samples']);
        self::assertTrue(json_decode($session->config,true)['sent_samples_complete']);
        Queue::assertNotPushed(ArchiveLesson::class);
        self::assertSame([], (new \ReflectionProperty(Gateway::class,'upstreams'))->getValue($f['gateway']));
    }

    public function test_failed_preparing_peer_and_restart_cannot_recreate_purged_content():void
    {
        $f=$this->fixture();
        DB::table('lessons')->where('id',$f['lesson'])->update(['deleted_at'=>now(),'purge_status'=>'queued']);
        (new PurgeLesson($f['lesson']))->handle();
        (new \ReflectionProperty(Gateway::class,'upstreams'))->setValue($f['gateway'],[]);
        (new \ReflectionProperty(Gateway::class,'preparing'))->setValue($f['gateway'],[$f['lesson']=>['sid'=>$f['sid'],'peer'=>$f['peer'],'context'=>$f['context'],'ready'=>false]]);
        $state=(new \ReflectionProperty(QwenSession::class,'onState'))->getValue($f['peer']);
        $state('failed');
        // Emulate another still-open session discovered by a later gateway restart.
        DB::table('model_sessions')->where('id',$f['sid'])->update(['status'=>'finishing','ended_at'=>null]);
        $lessonBefore=DB::table('lessons')->find($f['lesson']);
        Recovery::restart();
        self::assertEquals($lessonBefore,DB::table('lessons')->find($f['lesson']));
        self::assertSame('interrupted',DB::table('model_sessions')->where('id',$f['sid'])->value('status'));
        self::assertSame(0,DB::table('subtitle_units')->where('lesson_id',$f['lesson'])->count());
        self::assertSame(0,DB::table('recording_issues')->where('lesson_id',$f['lesson'])->count());
        self::assertSame(0,DB::table('lesson_events')->where('lesson_id',$f['lesson'])->count());
        Queue::assertNotPushed(ArchiveLesson::class,fn($job)=>(int)$job->lessonId===$f['lesson']);
    }

    public function test_official_usage_is_retained_after_actual_purge_without_body_or_events():void
    {
        $f=$this->fixture();
        DB::table('lessons')->where('id',$f['lesson'])->update(['deleted_at'=>now(),'purge_status'=>'queued']);
        (new PurgeLesson($f['lesson']))->handle();
        $event=['type'=>'usage','response_id'=>'late-usage','provider_event_id'=>'late-response','status'=>'completed','official'=>true,'usage'=>['total_tokens'=>3,'input_tokens'=>2,'output_tokens'=>1]];
        $this->persist($f,$event);$this->persist($f,$event);
        self::assertSame(1,DB::table('usage_records')->where('lesson_id',$f['lesson'])->count());
        self::assertTrue(DB::table('usage_records')->where('lesson_id',$f['lesson'])->value('official'));
        self::assertSame(0,DB::table('subtitle_units')->where('lesson_id',$f['lesson'])->count());
        self::assertSame(0,DB::table('lesson_events')->where('lesson_id',$f['lesson'])->count());
    }

    public function test_normal_ended_lesson_keeps_tail_and_finishes_archive_dispatch():void
    {
        $f=$this->fixture();
        $this->persist($f,$this->subtitle('source:fixture:0','streaming','Partial source','initial'));
        $this->persist($f,$this->subtitle('source:fixture:0','completed','Confirmed tail source','late-final'));
        $this->persist($f,$this->subtitle('translation:tail:0','completed','Confirmed tail translation','late-translation','translation'));
        Recovery::interrupted(DB::table('lessons')->find($f['lesson']),'fixture_provider_warning',$f['sid']);
        $state=(new \ReflectionProperty(QwenSession::class,'onState'))->getValue($f['peer']);
        $state('finished');
        self::assertSame('Confirmed tail source',DB::table('subtitle_units')->where('lesson_id',$f['lesson'])->where('kind','source')->value('original_text'));
        self::assertSame('completed',DB::table('subtitle_units')->where('lesson_id',$f['lesson'])->where('kind','source')->value('status'));
        self::assertSame(2,DB::table('subtitle_units')->where('lesson_id',$f['lesson'])->count());
        self::assertSame(1,DB::table('recording_issues')->where('lesson_id',$f['lesson'])->count());
        self::assertGreaterThanOrEqual(4,DB::table('lesson_events')->where('lesson_id',$f['lesson'])->count());
        self::assertSame('ended',DB::table('lessons')->where('id',$f['lesson'])->value('status'));
        self::assertSame('finished',DB::table('lessons')->where('id',$f['lesson'])->value('translation_status'));
        Queue::assertPushed(ArchiveLesson::class,fn($job)=>(int)$job->lessonId===$f['lesson']);
    }

    private function fixture():array
    {
        $user=DB::table('users')->insertGetId(['name'=>'Synthetic late tail owner','student_id'=>'late-'.Str::uuid(),'role'=>'user','password'=>'unused','must_change_password'=>false]);
        $course=DB::table('courses')->insertGetId(['name'=>'Synthetic late tail course']);
        $lesson=DB::table('lessons')->insertGetId(['course_id'=>$course,'creator_id'=>$user,'recorder_user_id'=>$user,'name'=>'Synthetic ended tail lesson','status'=>'ended','ended_at'=>now(),'translation_status'=>'finishing','target_language'=>'zh','media_samples'=>1600,'recorder_epoch'=>1,'idempotency_key'=>(string)Str::uuid()]);
        $sid=(string)Str::uuid();DB::table('model_sessions')->insert(['id'=>$sid,'lesson_id'=>$lesson,'recorder_user_id'=>$user,'base_sample'=>0,'model'=>'explicit-fixture','status'=>'finishing','config'=>'{"epoch":1}']);
        $gateway=new Gateway();$context=(object)['base'=>0,'epoch'=>1,'key'=>'synthetic-unused-connection'];
        $cfg=new QwenConfig(['model'=>'qwen3.8-livetranslate-flash-realtime','region'=>'cn-beijing','workspace_id'=>'fixture','endpoint'=>'ws://127.0.0.1:65534/api-ws/v1/realtime','api_key'=>'explicit-unused-fixture','allow_test_endpoint'=>true],true);
        $peer=(new \ReflectionMethod(Gateway::class,'newPeer'))->invoke($gateway,$lesson,$sid,$cfg,'zh',$context);
        // No socket is opened; this recorded count models already-sent fixture audio at tail drain.
        (new \ReflectionProperty(QwenSession::class,'sentSamples'))->setValue($peer,1600);
        (new \ReflectionProperty(Gateway::class,'upstreams'))->setValue($gateway,[$lesson=>['sid'=>$sid,'peer'=>$peer,'context'=>$context]]);
        return compact('lesson','sid','gateway','peer','context');
    }

    private function subtitle(string $unit,string $status,string $text,string $event,string $kind='source'):array
    {
        return ['type'=>'subtitle.upsert','unit_id'=>$unit,'kind'=>$kind,'item_id'=>$unit,'status'=>$status,'text'=>$text,'provider_event_id'=>$event];
    }

    private function persist(array $f,array $event):void
    {
        (new \ReflectionMethod(Gateway::class,'modelEvent'))->invoke($f['gateway'],$f['lesson'],$f['sid'],0,$event);
    }
}
