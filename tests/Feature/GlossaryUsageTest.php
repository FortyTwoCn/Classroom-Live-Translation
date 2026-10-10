<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Domain\{Access,Failure};
use App\Glossary\Glossary;
use App\Reliability\Usage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\{Carbon,Str};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Real PostgreSQL; terms, provider usage and accounts are explicitly test fixtures. */
final class GlossaryUsageTest extends TestCase
{
    use DatabaseTransactions;
    private object $admin;
    private object $user;
    private int $course;
    private int $lesson;
    private string $sid;
    private array $limits=['max_lesson_seconds'=>2,'max_daily_audio_seconds'=>3,'warning_ratio'=>0.8];
    protected function setUp():void
    {
        parent::setUp();self::assertSame('pgsql',DB::connection()->getDriverName());self::assertSame('classroom_test',DB::connection()->getDatabaseName());
        foreach(['admin','user']as $role){$id=DB::table('users')->insertGetId(['student_id'=>'usage-'.Str::uuid(),'name'=>'Fixture '.$role,'password'=>'unused','role'=>$role,'must_change_password'=>false]);$this->$role=Access::user($id);}
        $this->course=DB::table('courses')->insertGetId(['name'=>'Fixture aerodynamic course']);$this->lesson=$this->lesson($this->course);
        $this->sid=(string)Str::uuid();DB::table('model_sessions')->insert(['id'=>$this->sid,'lesson_id'=>$this->lesson,'base_sample'=>0,'model'=>'fixture','region'=>'cn-beijing','config'=>'{}','status'=>'finished','created_at'=>'2026-10-09 00:00:00+00','ended_at'=>'2026-10-09 00:00:10+00']);
        DB::table('daily_audio_usage')->updateOrInsert(['day'=>now()->utc()->format('Y-m-d')],['sample_count'=>0]);
    }
    protected function tearDown():void{Carbon::setTestNow();parent::tearDown();}

    public function test_directional_terms_version_conflict_and_snapshot_are_immutable():void
    {
        $g=new Glossary;$r=$g->save($this->course,null,$this->admin,$this->term(),1);self::assertSame(2,$r['version']);
        $g->save($this->course,null,$this->admin,$this->term('lift coefficient','升力系数'),2);
        $g->save($this->course,null,$this->admin,$this->term('airfoil','профіль крила','en','uk'),3);
        $snap=Glossary::snapshot($this->lesson,$this->course,'zh');self::assertSame(4,$snap['version']);self::assertSame(['airfoil'=>'翼型','lift coefficient'=>'升力系数'],$snap['phrases']);
        $g->save($this->course,$r['entry']['id'],$this->admin,['target_text'=>'新翼型'],4);
        self::assertEquals($snap,Glossary::snapshot($this->lesson,$this->course,'zh'));
        $this->failure('glossary_version_conflict',fn()=>$g->save($this->course,null,$this->admin,$this->term('angle of attack','迎角'),4));
        DB::table('lessons')->where('id',$this->lesson)->update(['status'=>'ended']);$next=$this->lesson($this->course);
        self::assertSame('新翼型',Glossary::snapshot($next,$this->course,'zh')['phrases']['airfoil']);self::assertSame(5,DB::table('courses')->where('id',$this->course)->value('glossary_version'));
    }
    public function test_source_direction_collisions_are_rejected_because_provider_map_has_one_key():void
    {
        $g=new Glossary;$g->save($this->course,null,$this->admin,$this->term(),1);
        $this->failure('glossary_phrase_conflict',fn()=>$g->save($this->course,null,$this->admin,$this->term('airfoil','不同解释','de','zh'),2));
        self::assertSame(1,DB::table('course_terms')->where('course_id',$this->course)->count());self::assertSame(2,DB::table('courses')->where('id',$this->course)->value('glossary_version'));
        $g->save($this->course,null,$this->admin,$this->term('airfoil','翼型','de','zh'),2);self::assertSame(1,Glossary::snapshot($this->lesson,$this->course,'zh')['count']);
    }
    public function test_soft_delete_restore_and_disabled_terms_preserve_history_and_snapshots():void
    {
        $g=new Glossary;$r=$g->save($this->course,null,$this->admin,$this->term(),1);$snap=Glossary::snapshot($this->lesson,$this->course,'zh');
        $g->deletion($this->course,$r['entry']['id'],$this->admin,true,2);self::assertSame(0,$g->listing($this->course,$this->admin)['total']);self::assertSame(1,$g->listing($this->course,$this->admin,['deleted'=>'only'])['total']);
        $r=$g->deletion($this->course,$r['entry']['id'],$this->admin,false,3);self::assertSame(3,$r['entry']['row_version']);
        $g->save($this->course,$r['entry']['id'],$this->admin,['enabled'=>false],4);self::assertEquals($snap,Glossary::snapshot($this->lesson,$this->course,'zh'));
        DB::table('lessons')->where('id',$this->lesson)->update(['status'=>'ended']);self::assertSame([],Glossary::snapshot($this->lesson($this->course),$this->course,'zh')['phrases']);
    }
    public function test_glossary_byte_payload_caps_validate_before_commit():void
    {
        $g=new Glossary;$this->failure('glossary_term_invalid',fn()=>$g->save($this->course,null,$this->admin,$this->term('airfoil',str_repeat('词',334)),1));
        for($i=0;$i<300;$i++)DB::table('course_terms')->insert(['course_id'=>$this->course,'source_language'=>'en','target_language'=>'zh','source_text'=>str_repeat('s',232).sprintf('%04d',$i),'target_text'=>str_repeat('译',4)]);
        $this->failure('glossary_local_limit',fn()=>$g->save($this->course,null,$this->admin,$this->term(),1));self::assertSame(1,DB::table('courses')->where('id',$this->course)->value('glossary_version'));
        $this->failure('glossary_local_limit',fn()=>Glossary::snapshot($this->lesson,$this->course,'zh'));self::assertFalse(DB::table('lesson_glossary_snapshots')->where('lesson_id',$this->lesson)->exists());
    }
    public function test_glossary_stale_admin_and_guest_cannot_mutate():void
    {
        $g=new Glossary;$this->failure('login_required',fn()=>$g->save($this->course,null,null,$this->term(),1));$this->failure('forbidden',fn()=>$g->listing($this->course,$this->user));
        DB::table('users')->where('id',$this->admin->id)->update(['role'=>'user']);$this->failure('forbidden',fn()=>$g->save($this->course,null,$this->admin,$this->term(),1));
        DB::table('users')->where('id',$this->admin->id)->update(['role'=>'admin','auth_version'=>2]);$this->failure('session_revoked',fn()=>$g->save($this->course,null,$this->admin,$this->term(),1));
    }
    public function test_glossary_http_recent_auth_confirmation_pagination_and_literal_search():void
    {
        $path='/api/admin/courses/'.$this->course.'/glossary';$this->getJson($path)->assertStatus(401);
        $this->withSession(['uid'=>$this->admin->id])->postJson($path,$this->term()+['expected_glossary_version'=>1])->assertStatus(403)->assertJsonPath('code','reauth_required');
        $r=$this->withSession(['uid'=>$this->admin->id,'reauth_until'=>time()+60])->postJson($path,$this->term('air%foil','翼型')+['expected_glossary_version'=>1])->assertOk()->json();
        $this->getJson($path.'?search=%25')->assertOk()->assertJsonPath('total',1)->assertJsonPath('per_page',30);
        $this->getJson($path.'?source_language=&target_language=&enabled=')->assertOk()->assertJsonPath('total',1);
        $this->deleteJson($path.'/'.$r['entry']['id'],['expected_glossary_version'=>2])->assertStatus(400)->assertJsonPath('code','confirmation_required');
        $this->deleteJson($path.'/'.$r['entry']['id'],['expected_glossary_version'=>2,'confirmed'=>true])->assertOk()->assertJsonPath('deleted',true);
    }

    public function test_sample_quota_is_exact_rolls_back_and_rejects_one_sample_over():void
    {
        for($i=0;$i<20;$i++)$this->accept(1600);self::assertSame(32000,(int)DB::table('daily_audio_usage')->where('day',now()->utc()->format('Y-m-d'))->value('sample_count'));
        $this->failure('duration_limit',fn()=>$this->accept(1));$this->failure('duration_limit',fn()=>Usage::assertStart($this->lesson,$this->limits));
        $course=DB::table('courses')->insertGetId(['name'=>'Other fixture']);$other=$this->lesson($course);for($i=0;$i<10;$i++)$this->accept(1600,$other);
        $this->failure('daily_audio_limit',fn()=>$this->accept(1,$other));$this->failure('daily_audio_limit',fn()=>Usage::assertStart($other,$this->limits));self::assertSame(48000,(int)DB::table('daily_audio_usage')->where('day',now()->utc()->format('Y-m-d'))->value('sample_count'));
    }
    public function test_storage_transaction_failure_rolls_back_usage_and_warning_outbox():void
    {
        for($i=0;$i<15;$i++)$this->accept(1600);
        try{DB::transaction(function(){Usage::acceptSamples(DB::table('lessons')->where('id',$this->lesson)->lockForUpdate()->first(),1600,$this->limits);throw new \RuntimeException('fixture storage failure');});self::fail('Failure did not roll back.');}catch(\RuntimeException $e){self::assertSame('fixture storage failure',$e->getMessage());}
        self::assertSame(24000,(int)DB::table('daily_audio_usage')->where('day',now()->utc()->format('Y-m-d'))->value('sample_count'));self::assertSame(0,DB::table('usage_alerts')->where('lesson_id',$this->lesson)->count());
        $this->accept(1600);$this->accept(1600);self::assertSame(1,DB::table('usage_alerts')->where('lesson_id',$this->lesson)->where('scope','lesson')->count());self::assertSame(1,DB::table('lesson_events')->where('lesson_id',$this->lesson)->where('event_type','usage.warning')->count());
    }
    public function test_utc_day_reset_does_not_reset_lesson_duration():void
    {
        Carbon::setTestNow('2026-10-10 23:59:59+00');$this->accept(1600);Carbon::setTestNow('2026-10-11 00:00:00+00');$this->accept(1600);
        self::assertSame(1600,(int)DB::table('daily_audio_usage')->where('day','2026-10-10')->value('sample_count'));self::assertSame(1600,(int)DB::table('daily_audio_usage')->where('day','2026-10-11')->value('sample_count'));self::assertSame(3200,(int)DB::table('lessons')->where('id',$this->lesson)->value('media_samples'));
    }
    public function test_usage_receipts_deduplicate_responses_and_event_only_records():void
    {
        $e=$this->usage();self::assertTrue(Usage::recordEvent($this->lesson,$this->sid,$e));$e['provider_event_id']='replayed-another-id';self::assertFalse(Usage::recordEvent($this->lesson,$this->sid,$e));
        $e['response_id']=null;self::assertTrue(Usage::recordEvent($this->lesson,$this->sid,$e));self::assertFalse(Usage::recordEvent($this->lesson,$this->sid,$e));
        self::assertSame(2,DB::table('usage_records')->where('model_session_id',$this->sid)->count());$raw=DB::table('usage_records')->where('model_session_id',$this->sid)->first();self::assertTrue($raw->official);self::assertEquals($this->usage()['usage'],json_decode($raw->usage,true));
    }
    public function test_usage_session_mismatch_or_missing_identity_never_inserts_raw_data():void
    {
        $this->failure('usage_session_mismatch',fn()=>Usage::recordEvent($this->lesson,(string)Str::uuid(),$this->usage()));$e=$this->usage();unset($e['response_id'],$e['provider_event_id']);$this->failure('usage_event_identity_missing',fn()=>Usage::recordEvent($this->lesson,$this->sid,$e));self::assertSame(0,DB::table('usage_receipts')->where('model_session_id',$this->sid)->count());
    }
    public function test_prices_are_exact_and_missing_text_input_is_unknown_instead_of_free():void
    {
        Usage::recordEvent($this->lesson,$this->sid,$this->usage());$prices=$this->prices();$s=Usage::summary($this->lesson,$prices);self::assertTrue($s['costs']['complete']);self::assertSame('0.001587000000',$s['costs']['totals'][0]['estimated_total']);self::assertSame(211,$s['model']['tokens']['total']);self::assertSame(47,$s['model']['tokens']['input_details']['text']);
        unset($prices[1]);$s=Usage::summary($this->lesson,array_values($prices));self::assertFalse($s['costs']['complete']);self::assertNull($s['costs']['totals'][0]['estimated_total']);self::assertSame('text_input',$s['costs']['unpriced'][0]['item']);self::assertSame(47,$s['costs']['unpriced'][0]['tokens']);
    }
    public function test_effective_prices_and_currencies_are_separate_and_no_float_rounding_occurs():void
    {
        Usage::recordEvent($this->lesson,$this->sid,$this->usage());$row=DB::table('usage_records')->where('model_session_id',$this->sid)->first();DB::table('usage_records')->where('id',$row->id)->update(['created_at'=>'2026-10-09 01:00:00+00']);
        $e=$this->usage();$e['response_id']='r2';Usage::recordEvent($this->lesson,$this->sid,$e);DB::table('usage_records')->where('model_session_id',$this->sid)->where('response_id','r2')->update(['created_at'=>'2026-10-10 01:00:00+00']);
        $p=$this->prices();foreach($this->prices()as $next){$next['currency']='USD';$next['unit_price']='0.123456789123';$next['effective_at']='2026-10-10T00:00:00Z';$p[]=$next;}
        $s=Usage::summary($this->lesson,$p);self::assertCount(2,$s['costs']['totals']);self::assertSame('CNY',$s['costs']['totals'][0]['currency']);self::assertSame('USD',$s['costs']['totals'][1]['currency']);self::assertSame('0.000026049382',$s['costs']['totals'][1]['estimated_total']);self::assertSame(2,$s['model']['official_records']);
    }
    public function test_unknown_official_usage_and_legacy_duplicate_are_reported_honestly():void
    {
        Usage::recordEvent($this->lesson,$this->sid,$this->usage());DB::table('usage_records')->insert(['lesson_id'=>$this->lesson,'model_session_id'=>$this->sid,'response_id'=>'r','status'=>'completed','official'=>true,'usage'=>json_encode($this->usage()['usage'])]);
        $e=$this->usage();$e['response_id']='missing';$e['official']=false;$e['usage']=null;Usage::recordEvent($this->lesson,$this->sid,$e);
        $s=Usage::summary($this->lesson,$this->prices());self::assertSame(1,$s['model']['duplicate_records_ignored']);self::assertSame(1,$s['model']['unknown_usage_records']);self::assertSame(211,$s['model']['tokens']['total']);self::assertNull($s['costs']['totals'][0]['estimated_total']);self::assertFalse($s['costs']['complete']);
        self::assertStringNotContainsString('Fixture',json_encode($s));self::assertStringNotContainsString('api_key',json_encode($s));
    }
    public function test_price_validation_rejects_negative_float_duplicate_and_invalid_dates():void
    {
        foreach([['unit_price'=>-1],['unit_price'=>'-1'],['unit_price'=>1.5],['unit_price'=>'1e2'],['effective_at'=>'2026-02-30T00:00:00Z'],['currency'=>'usd'],['item'=>'all']]as $change){$p=$this->prices()[0];$this->failure('usage_price_invalid',fn()=>Usage::validatePrices([array_replace($p,$change)]));}
        $this->failure('usage_price_invalid',fn()=>Usage::validatePrices([$this->prices()[0],$this->prices()[0]]));self::assertSame([] ,Usage::validatePrices([]));
    }
    public function test_price_provenance_is_preserved_and_unsafe_urls_rejected():void
    {
        $p=$this->prices()[0]+['source_url'=>'https://www.alibabacloud.com/help/en/model-studio/model-pricing','verified_at'=>'2026-10-09'];$r=Usage::validatePrices([$p]);self::assertSame($p['source_url'],$r[0]['source_url']);self::assertSame('2026-10-09T00:00:00Z',$r[0]['verified_at']);
        foreach(['javascript:alert(1)','https://user:password@example.com','http://example.com']as $url)$this->failure('usage_price_invalid',fn()=>Usage::validatePrices([array_replace($p,['source_url'=>$url])]));
        $this->failure('usage_price_invalid',fn()=>Usage::validatePrices([array_replace($p,['verified_at'=>'2026-02-30'])]));
    }
    public function test_date_filters_cannot_count_historical_duplicate_again_on_a_later_day():void
    {
        Usage::recordEvent($this->lesson,$this->sid,$this->usage());DB::table('usage_records')->where('model_session_id',$this->sid)->update(['created_at'=>'2026-10-09 01:00:00+00']);
        DB::table('usage_records')->insert(['lesson_id'=>$this->lesson,'model_session_id'=>$this->sid,'response_id'=>'r','status'=>'completed','official'=>true,'usage'=>json_encode($this->usage()['usage']),'created_at'=>'2026-10-10 01:00:00+00']);
        $s=Usage::summary($this->lesson,$this->prices(),['from'=>'2026-10-10','to'=>'2026-10-10']);self::assertSame(0,$s['model']['official_records']);self::assertSame(1,$s['model']['duplicate_records_ignored']);self::assertNull($s['model']['tokens']['total']);
    }
    public function test_usage_http_guest_denied_member_safe_and_account_admin_only():void
    {
        $path='/api/lessons/'.$this->lesson.'/usage';$this->getJson($path)->assertStatus(401);Usage::recordEvent($this->lesson,$this->sid,$this->usage());
        $r=$this->withSession(['uid'=>$this->user->id])->getJson($path)->assertOk()->assertJsonPath('scope','lesson')->assertJsonPath('model.tokens.total',211)->json();self::assertArrayHasKey('limits',$r['audio']);self::assertArrayHasKey('no_pricing_configured',$r['costs']);self::assertStringNotContainsString('api_key',json_encode($r));
        $this->getJson('/api/admin/usage')->assertStatus(403);$this->withSession(['uid'=>$this->admin->id])->getJson('/api/admin/usage?course_id='.$this->course.'&to=2026-12-31')->assertOk()->assertJsonPath('scope','account')->assertJsonPath('model.session_count',1);
        DB::table('users')->where('id',$this->user->id)->update(['must_change_password'=>true]);$this->withSession(['uid'=>$this->user->id])->getJson($path)->assertStatus(403);
    }
    public function test_model_session_purposes_and_interruptions_are_separate_from_official_tokens():void
    {
        DB::table('model_sessions')->where('id',$this->sid)->update(['config'=>'{"purpose":"initial"}']);
        foreach(['resume','rotation']as $purpose)DB::table('model_sessions')->insert(['id'=>(string)Str::uuid(),'lesson_id'=>$this->lesson,'base_sample'=>0,'model'=>'fixture','region'=>'cn-beijing','config'=>json_encode(['purpose'=>$purpose]),'status'=>$purpose==='resume'?'failed':'interrupted','created_at'=>'2026-10-09 00:00:00+00','ended_at'=>'2026-10-09 00:00:10+00']);
        $s=Usage::summary($this->lesson,[]);self::assertSame(3,$s['model']['session_count']);self::assertSame(1,$s['model']['initial_sessions']);self::assertSame(1,$s['model']['resume_sessions']);self::assertSame(1,$s['model']['rotation_sessions']);self::assertSame(1,$s['model']['failed_sessions']);self::assertSame(1,$s['model']['interrupted_sessions']);self::assertNull($s['model']['tokens']['total']);
    }
    private function term(string $source='airfoil',string $target='翼型',string $from='en',string $to='zh'):array{return ['source_language'=>$from,'target_language'=>$to,'source_text'=>$source,'target_text'=>$target,'note'=>'Fixture mapping','enabled'=>true];}
    private function lesson(int $course):int{return DB::table('lessons')->insertGetId(['course_id'=>$course,'creator_id'=>$this->user->id,'name'=>'Fixture lesson','target_language'=>'zh','idempotency_key'=>(string)Str::uuid()]);}
    private function accept(int $samples,?int $id=null):void{DB::transaction(function()use($samples,$id){$id??=$this->lesson;$row=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();Usage::acceptSamples($row,$samples,$this->limits);DB::table('lessons')->where('id',$id)->increment('media_samples',$samples);});}
    private function usage():array{return ['type'=>'usage','response_id'=>'r','provider_event_id'=>'e','status'=>'completed','official'=>true,'usage'=>['total_tokens'=>211,'input_tokens'=>166,'output_tokens'=>45,'input_tokens_details'=>['audio_tokens'=>119,'text_tokens'=>47],'output_tokens_details'=>['text_tokens'=>45]]];}
    private function prices():array{$out=[];foreach(['audio_input'=>'8.50','text_input'=>'2.00','text_output'=>'10.70']as $item=>$price)$out[]=['region'=>'cn-beijing','currency'=>'CNY','item'=>$item,'unit_price'=>$price,'effective_at'=>'2026-01-01T00:00:00Z'];return $out;}
    private function failure(string $code,callable $run):void{try{$run();self::fail('Expected '.$code);}catch(Failure $e){self::assertSame($code,$e->errorCode);}}
}
