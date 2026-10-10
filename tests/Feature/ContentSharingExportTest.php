<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Content\SubtitleService;
use App\Domain\{Access,Failure};
use App\Exports\{ExportService,SubtitleFormatter};
use App\Sharing\SharingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt,DB,Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

/** Real PostgreSQL and real files; subtitle provider content is explicitly a fixture. */
final class ContentSharingExportTest extends TestCase
{
    use DatabaseTransactions;
    private object $user;
    private object $other;
    private object $admin;
    private int $course;
    private int $lesson;
    private string $model;
    private array $units;
    private array $artifacts=[];
    protected function setUp():void
    {
        parent::setUp();self::assertSame('pgsql',DB::connection()->getDriverName());Queue::fake();
        foreach(['user','other','admin'] as $role){$id=DB::table('users')->insertGetId(['student_id'=>'content-'.Str::uuid(),'name'=>'Name '.$role,'password'=>'unused','role'=>$role==='admin'?'admin':'user','must_change_password'=>false]);$this->$role=Access::user($id);}
        $this->course=DB::table('courses')->insertGetId(['name'=>'中文 Course','guest_access'=>'link']);
        $this->lesson=DB::table('lessons')->insertGetId(['course_id'=>$this->course,'creator_id'=>$this->other->id,'name'=>'Урок <安全>','status'=>'live','target_language'=>'zh','media_samples'=>64000,'idempotency_key'=>(string)Str::uuid()]);
        $this->model=(string)Str::uuid();DB::table('model_sessions')->insert(['id'=>$this->model,'lesson_id'=>$this->lesson,'base_sample'=>0,'model'=>'fixture','config'=>'{}','status'=>'ready']);
        $this->units=[$this->unit('source','Hello original','asr','asr','source_identity'),$this->unit('translation','你好 Привіт','translated','asr','explicit_previous_item_id')];
        SharingService::ensure($this->lesson);
    }
    protected function tearDown():void
    {
        foreach($this->artifacts as $path){if(is_file($path))unlink($path);if(is_file($path.'.tmp'))unlink($path.'.tmp');if(is_dir(dirname($path)))rmdir(dirname($path));}parent::tearDown();
    }
    public function test_revision_is_atomic_idempotent_conflict_safe_and_original_is_immutable():void
    {
        $service=new SubtitleService;$key=(string)Str::uuid();$changes=[['unit_id'=>$this->units[0],'expected_version'=>1,'text'=>'Corrected English'],['unit_id'=>$this->units[1],'expected_version'=>1,'text'=>'校对 Привіт']];
        $result=$service->revise($this->lesson,$this->user,$changes,$key);self::assertSame(2,$result['units'][0]['version']);
        self::assertEquals($result,$service->revise($this->lesson,$this->user,$changes,$key));self::assertSame(2,DB::table('subtitle_revisions')->where('lesson_id',$this->lesson)->count());
        $changes[0]['text']='competing';$changes[0]['expected_version']=2;
        $this->failure('subtitle_version_conflict',fn()=>$service->revise($this->lesson,$this->other,$changes,(string)Str::uuid()));
        self::assertSame('Corrected English',DB::table('subtitle_units')->where('id',$this->units[0])->value('current_text'));self::assertSame('Hello original',DB::table('subtitle_units')->where('id',$this->units[0])->value('original_text'));
        $history=$service->history($this->lesson,$this->units[0],$this->user);self::assertSame('Hello original',$history['original']['text']);self::assertFalse(property_exists($history['revisions'][0],'student_id'));self::assertCount(2,$history['related_units']);self::assertSame('你好 Привіт',$history['related_units'][1]['original_text']);
        $service->restore($this->lesson,$this->units[0],$this->user,null,2,(string)Str::uuid());self::assertSame(3,DB::table('subtitle_units')->where('id',$this->units[0])->value('version'));self::assertSame(3,DB::table('subtitle_revisions')->where('lesson_id',$this->lesson)->count());
    }
    public function test_frozen_incomplete_deleted_and_guest_fields_cannot_be_edited():void
    {
        $service=new SubtitleService;$changes=[['unit_id'=>$this->units[0],'expected_version'=>1,'text'=>'new']];
        $this->failure('login_required',fn()=>$service->revise($this->lesson,null,$changes,(string)Str::uuid()));
        $service->freeze($this->lesson,$this->admin,true);$this->failure('subtitle_editing_frozen',fn()=>$service->revise($this->lesson,$this->user,$changes,(string)Str::uuid()));$service->freeze($this->lesson,$this->admin,false);
        DB::table('subtitle_units')->where('id',$this->units[0])->update(['status'=>'incomplete']);$this->failure('subtitle_not_completed',fn()=>$service->revise($this->lesson,$this->user,$changes,(string)Str::uuid()));
        $service->deletion($this->lesson,$this->units[0],$this->admin,true);$this->failure('subtitle_unavailable',fn()=>$service->revise($this->lesson,$this->user,$changes,(string)Str::uuid()));$service->deletion($this->lesson,$this->units[0],$this->admin,false);self::assertNull(DB::table('subtitle_units')->where('id',$this->units[0])->value('deleted_at'));
    }
    public function test_stable_encrypted_share_is_lesson_scoped_and_migrates_across_session_regeneration():void
    {
        $service=new SharingService;$request=$this->shareRequest();$session=$request->session()->getId();
        $this->failure('forbidden',fn()=>Access::lesson($this->lesson,null,$session));$share=DB::table('lesson_shares')->where('lesson_id',$this->lesson)->first();$token=Crypt::decryptString($share->token_encrypted);self::assertNotSame($token,$share->token_encrypted);
        self::assertSame($this->lesson,$service->redeem($request,$token));self::assertTrue(SharingService::hasGrant($session,$this->lesson));
        $view=$service->view($this->lesson,null,$session);self::assertSame($view,$service->view($this->lesson,null,$session));self::assertStringContainsString('/s/'.$token,$view['url']);self::assertSame(1,DB::table('lesson_shares')->where('lesson_id',$this->lesson)->count());
        DB::table('lessons')->where('id',$this->lesson)->update(['status'=>'ended']);$later=DB::table('lessons')->insertGetId(['course_id'=>$this->course,'creator_id'=>$this->user->id,'name'=>'New lesson','target_language'=>'zh','idempotency_key'=>(string)Str::uuid()]);self::assertFalse(SharingService::hasGrant($session,$later));$this->failure('forbidden',fn()=>Access::lesson($later,null,$session));self::assertSame($this->lesson,$service->redeem($request,$token));
        $request->session()->regenerate(true);$newSession=$request->session()->getId();SharingService::migrateGrants($session,$newSession);$request->session()->save();self::assertFalse(SharingService::hasGrant($session,$this->lesson));self::assertTrue(SharingService::hasGrant($newSession,$this->lesson));
        $service->revoke($this->lesson,$this->admin);self::assertFalse(SharingService::hasGrant($newSession,$this->lesson));$this->failure('share_revoked',fn()=>$service->redeem($request,$token));
        $new=$service->reset($this->lesson,$this->admin,now()->addHour()->toIso8601String());self::assertNotSame($view['url'],$new['url']);$this->failure('share_invalid',fn()=>$service->redeem($request,$token));
    }
    public function test_expired_share_and_deleted_lesson_deny_access_without_minting_new_tokens():void
    {
        $share=DB::table('lesson_shares')->where('lesson_id',$this->lesson)->first();DB::table('lesson_shares')->where('id',$share->id)->update(['expires_at'=>now()->subMinute()]);$request=$this->shareRequest();
        $this->failure('share_expired',fn()=>(new SharingService)->redeem($request,Crypt::decryptString($share->token_encrypted)));
        DB::table('lesson_shares')->where('id',$share->id)->update(['expires_at'=>null]);DB::table('lessons')->where('id',$this->lesson)->update(['deleted_at'=>now()]);$this->failure('lesson_unavailable',fn()=>(new SharingService)->redeem($request,Crypt::decryptString($share->token_encrypted)));self::assertSame(1,DB::table('lesson_shares')->where('lesson_id',$this->lesson)->count());
    }
    public function test_erased_or_expired_session_cannot_resurrect_a_share_grant():void
    {
        $request=$this->shareRequest();$share=SharingService::ensure($this->lesson);(new SharingService)->redeem($request,Crypt::decryptString($share->token_encrypted));$session=$request->session()->getId();self::assertTrue(SharingService::hasGrant($session,$this->lesson));
        DB::table('sessions')->where('id',$session)->update(['last_activity'=>time()-7201]);self::assertFalse(SharingService::hasGrant($session,$this->lesson));
        DB::table('sessions')->where('id',$session)->update(['last_activity'=>time(),'payload'=>base64_encode(serialize([]))]);self::assertFalse(SharingService::hasGrant($session,$this->lesson));
    }
    public function test_http_routes_enforce_guest_export_editor_and_sensitive_recent_auth():void
    {
        $this->postJson('/api/lessons/'.$this->lesson.'/exports',$this->exportOptions('txt'))->assertStatus(401)->assertJsonPath('code','login_required');
        $this->getJson('/api/lessons/'.$this->lesson.'/share')->assertStatus(403)->assertJsonPath('code','forbidden');
        $session=['uid'=>$this->user->id,'auth_version'=>$this->user->auth_version];
        $this->withSession($session)->patchJson('/api/lessons/'.$this->lesson.'/subtitles',['changes'=>[['unit_id'=>$this->units[0],'expected_version'=>1,'text'=>'HTTP correction']],'idempotency_key'=>(string)Str::uuid()])->assertOk()->assertJsonPath('units.0.version',2);
        $this->withSession($session)->postJson('/api/admin/lessons/'.$this->lesson.'/share/reset',['confirmed'=>true])->assertStatus(403)->assertJsonPath('code','forbidden');
        $this->withSession(['uid'=>$this->admin->id,'auth_version'=>$this->admin->auth_version,'reauth_until'=>0])->patchJson('/api/admin/lessons/'.$this->lesson.'/editing',['frozen'=>true,'confirmed'=>true])->assertStatus(403)->assertJsonPath('code','reauth_required');
        $this->withSession(['uid'=>$this->admin->id,'auth_version'=>$this->admin->auth_version,'reauth_until'=>time()+900])->patchJson('/api/admin/lessons/'.$this->lesson.'/editing',['frozen'=>true,'confirmed'=>true])->assertOk()->assertJsonPath('editing_frozen',true);
    }
    public function test_http_share_redirect_is_fixed_and_carries_no_referrer_policy():void
    {
        config(['session.driver'=>'database']);app('session')->forgetDrivers();$share=SharingService::ensure($this->lesson);$token=Crypt::decryptString($share->token_encrypted);
        $this->get('/s/'.$token)->assertRedirect('/lessons/'.$this->lesson)->assertHeader('Referrer-Policy','no-referrer');
        $session=app('session')->driver()->getId();$this->withCredentials()->withCookie(config('session.cookie'),$session)->getJson('/api/lessons/'.$this->lesson.'/share')->assertOk()->assertJsonPath('lesson_id',$this->lesson)->assertJsonPath('url',url('/s/'.$token));
        (new SharingService)->revoke($this->lesson,$this->admin);$this->withCredentials()->withCookie(config('session.cookie'),$session)->getJson('/api/lessons/'.$this->lesson.'/share')->assertStatus(403);$this->get('/s/'.$token)->assertRedirect('/share-unavailable?code=share_revoked');
    }
    public function test_http_export_creation_status_and_controlled_file_download_recheck_owner():void
    {
        $session=['uid'=>$this->user->id,'auth_version'=>$this->user->auth_version];
        $response=$this->withSession($session)->postJson('/api/lessons/'.$this->lesson.'/exports',$this->exportOptions('txt'))->assertOk()->assertJsonPath('export.status','queued');$id=$response->json('export.id');$service=new ExportService;$service->build($id,new SubtitleFormatter);$file=$service->file($id,$this->user);$this->artifacts[]=$file['path'];
        $this->withSession($session)->getJson('/api/exports/'.$id)->assertOk()->assertJsonPath('export.status','ready');
        $download=$this->withSession($session)->get('/api/exports/'.$id.'/file')->assertOk()->assertDownload()->assertHeader('Cache-Control','no-store, private');self::assertStringContainsString(rawurlencode($file['filename']),$download->headers->get('Content-Disposition'));
        $this->withSession(['uid'=>$this->other->id,'auth_version'=>$this->other->auth_version])->get('/api/exports/'.$id.'/file')->assertStatus(404);
    }
    public function test_admin_subtitle_filters_include_history_restored_and_soft_deleted_units():void
    {
        $service=new SubtitleService;$service->revise($this->lesson,$this->user,[['unit_id'=>$this->units[0],'expected_version'=>1,'text'=>'Correction literal %_']],(string)Str::uuid());$service->deletion($this->lesson,$this->units[1],$this->admin,true);
        $filtered=$service->adminList($this->admin,['lesson_id'=>$this->lesson,'edited'=>'yes','search'=>'%_']);self::assertSame(1,$filtered['total']);self::assertSame($this->units[0],$filtered['units'][0]['id']);
        $deleted=$service->adminList($this->admin,['course_id'=>$this->course,'deleted'=>'only','kind'=>'translation']);self::assertSame(1,$deleted['total']);self::assertSame($this->units[1],$deleted['units'][0]['id']);
        $this->withSession(['uid'=>$this->user->id,'auth_version'=>$this->user->auth_version])->getJson('/api/admin/subtitles')->assertStatus(403);
        $this->withSession(['uid'=>$this->admin->id,'auth_version'=>$this->admin->auth_version])->getJson('/api/admin/subtitles?lesson_id='.$this->lesson.'&edited=yes')->assertOk()->assertJsonPath('total',1);
    }
    public function test_four_actual_files_are_valid_and_private_with_safe_metadata():void
    {
        $service=new ExportService;
        foreach(['txt','md','srt','vtt'] as $format){$task=$service->create($this->lesson,$this->user,$this->exportOptions($format));$service->build($task['id'],new SubtitleFormatter);$file=$service->file($task['id'],$this->user);$this->artifacts[]=$file['path'];$contents=file_get_contents($file['path']);self::assertStringContainsString('Hello original',$contents);self::assertStringContainsString('你好 Привіт',$contents);self::assertStringContainsString('private/subtitle-exports',str_replace('\\','/',$file['path']));self::assertStringNotContainsString('<安全>',$file['filename']);self::assertSame('ready',$service->status($task['id'],$this->user)['status']);if($format==='srt')self::assertStringStartsWith("1\n00:00:01,000 --> 00:00:02,000\n",$contents);if($format==='vtt')self::assertStringStartsWith('WEBVTT',$contents);}
    }
    public function test_actual_database_queue_serializes_only_export_id_and_worker_builds_file():void
    {
        Queue::swap(Queue::getFacadeRoot()->queue);
        $service=new ExportService;$task=$service->create($this->lesson,$this->user,$this->exportOptions('vtt'));
        $queued=DB::table('jobs')->where('queue','exports')->where('payload','like','%'.$task['id'].'%')->first();self::assertNotNull($queued);self::assertStringNotContainsString('Hello original',$queued->payload);
        app('queue.worker')->runNextJob('database','exports',new \Illuminate\Queue\WorkerOptions(sleep:0,maxTries:3,timeout:300));
        self::assertSame('ready',$service->status($task['id'],$this->user)['status']);self::assertFalse(DB::table('jobs')->where('id',$queued->id)->exists());$file=$service->file($task['id'],$this->user);$this->artifacts[]=$file['path'];self::assertStringStartsWith('WEBVTT',file_get_contents($file['path']));
    }
    public function test_snapshot_excludes_later_edits_new_units_and_unconfirmed_fields_and_preserves_original_option():void
    {
        $service=new ExportService;$options=$this->exportOptions('txt');$task=$service->create($this->lesson,$this->user,$options);self::assertSame($task['id'],$service->create($this->lesson,$this->user,$options)['id']);
        (new SubtitleService)->revise($this->lesson,$this->user,[['unit_id'=>$this->units[0],'expected_version'=>1,'text'=>'New correction']],(string)Str::uuid());$this->unit('source','LATER_SENTENCE','later','later','source_identity');$this->unit('source','UNCONFIRMED','stream','stream','source_identity','streaming');
        $service->build($task['id'],new SubtitleFormatter);$first=$service->file($task['id'],$this->user);$this->artifacts[]=$first['path'];$contents=file_get_contents($first['path']);self::assertStringContainsString('Hello original',$contents);self::assertStringNotContainsString('New correction',$contents);self::assertStringNotContainsString('LATER_SENTENCE',$contents);
        $current=$service->create($this->lesson,$this->user,$this->exportOptions('txt'));$original=$service->create($this->lesson,$this->user,$this->exportOptions('txt','original'));
        foreach([$current,$original] as $item){$service->build($item['id'],new SubtitleFormatter);$file=$service->file($item['id'],$this->user);$this->artifacts[]=$file['path'];$text=file_get_contents($file['path']);self::assertStringNotContainsString('UNCONFIRMED',$text);self::assertStringContainsString($item['text_version']==='original'?'Hello original':'New correction',$text);self::assertSame(1,$service->status($item['id'],$this->user)['counts']['skipped_incomplete']);}
    }
    public function test_permission_rechecked_at_create_status_build_retry_and_download():void
    {
        $service=new ExportService;$this->failure('login_required',fn()=>$service->create($this->lesson,null,$this->exportOptions('txt')));$task=$service->create($this->lesson,$this->user,$this->exportOptions('txt'));
        $this->failure('export_unavailable',fn()=>$service->status($task['id'],$this->other));$this->failure('export_unavailable',fn()=>$service->file($task['id'],$this->other));
        DB::table('users')->where('id',$this->user->id)->update(['disabled'=>true]);$this->failure('login_required',fn()=>$service->status($task['id'],$this->user));$service->build($task['id'],new SubtitleFormatter);self::assertSame('export_permission_revoked',DB::table('subtitle_exports')->where('id',$task['id'])->value('failure_code'));
        DB::table('users')->where('id',$this->user->id)->update(['disabled'=>false]);self::assertSame('queued',$service->retry($task['id'],$this->user)['status']);$service->build($task['id'],new SubtitleFormatter);$file=$service->file($task['id'],$this->user);$this->artifacts[]=$file['path'];
        DB::table('courses')->where('id',$this->course)->update(['enabled'=>false]);$this->failure('lesson_unavailable',fn()=>$service->file($task['id'],$this->user));DB::table('courses')->where('id',$this->course)->update(['enabled'=>true]);DB::table('users')->where('id',$this->user->id)->update(['must_change_password'=>true]);$this->failure('password_change_required',fn()=>$service->file($task['id'],$this->user));
    }
    public function test_no_content_bad_times_concurrency_and_expiry_have_explicit_results():void
    {
        $service=new ExportService;DB::table('subtitle_units')->where('lesson_id',$this->lesson)->update(['status'=>'incomplete']);$this->failure('export_no_content',fn()=>$service->create($this->lesson,$this->user,$this->exportOptions('txt')));DB::table('subtitle_units')->where('lesson_id',$this->lesson)->update(['status'=>'completed','start_sample'=>null,'end_sample'=>null]);
        $task=$service->create($this->lesson,$this->user,$this->exportOptions('srt'));$service->build($task['id'],new SubtitleFormatter);$failed=$service->status($task['id'],$this->user);self::assertSame('failed',$failed['status']);self::assertSame('export_empty_timed',$failed['failure_code']);self::assertSame(1,$failed['counts']['skipped_timing']);
        $txt=$service->create($this->lesson,$this->user,$this->exportOptions('txt'));$another=$service->create($this->lesson,$this->user,$this->exportOptions('md'));$this->failure('export_concurrency_limit',fn()=>$service->create($this->lesson,$this->user,$this->exportOptions('vtt')));
        $service->build($txt['id'],new SubtitleFormatter);$file=$service->file($txt['id'],$this->user);$this->artifacts[]=$file['path'];DB::table('subtitle_exports')->where('id',$txt['id'])->update(['expires_at'=>now()->subSecond()]);$this->failure('export_expired',fn()=>$service->file($txt['id'],$this->user));self::assertFileDoesNotExist($file['path']);self::assertSame(0,DB::table('subtitle_export_rows')->where('export_id',$txt['id'])->count());self::assertSame('expired',$service->status($txt['id'],$this->user)['status']);
    }
    public function test_completed_but_empty_provider_fields_are_not_exportable():void
    {
        DB::table('subtitle_units')->where('lesson_id',$this->lesson)->update(['original_text'=>" \r\n\t"]);$service=new ExportService;$this->failure('export_no_content',fn()=>$service->create($this->lesson,$this->user,$this->exportOptions('txt')));
        DB::table('subtitle_units')->where('id',$this->units[0])->update(['original_text'=>'Real completed field']);$task=$service->create($this->lesson,$this->user,$this->exportOptions('txt'));$service->build($task['id'],new SubtitleFormatter);self::assertSame(1,$service->status($task['id'],$this->user)['counts']['skipped_empty']);$file=$service->file($task['id'],$this->user);$this->artifacts[]=$file['path'];self::assertStringContainsString('Real completed field',file_get_contents($file['path']));
    }
    public function test_stale_service_actor_cannot_write_after_account_revocation_or_role_demotion():void
    {
        $service=new SubtitleService;$changes=[['unit_id'=>$this->units[0],'expected_version'=>1,'text'=>'Unauthorized correction']];
        DB::table('users')->where('id',$this->user->id)->update(['disabled'=>true]);$this->failure('login_required',fn()=>$service->revise($this->lesson,$this->user,$changes,(string)Str::uuid()));
        DB::table('users')->where('id',$this->user->id)->update(['disabled'=>false]);DB::table('users')->where('id',$this->user->id)->increment('auth_version');$this->failure('session_revoked',fn()=>$service->restore($this->lesson,$this->units[0],$this->user,null,1,(string)Str::uuid()));$this->failure('session_revoked',fn()=>(new ExportService)->create($this->lesson,$this->user,$this->exportOptions('txt')));
        DB::table('users')->where('id',$this->admin->id)->update(['role'=>'user']);$this->failure('forbidden',fn()=>$service->freeze($this->lesson,$this->admin,true));$this->failure('forbidden',fn()=>$service->deletion($this->lesson,$this->units[0],$this->admin,true));$this->failure('forbidden',fn()=>(new SharingService)->revoke($this->lesson,$this->admin));
        self::assertSame(1,DB::table('subtitle_units')->where('id',$this->units[0])->value('version'));self::assertSame(0,DB::table('subtitle_revisions')->where('lesson_id',$this->lesson)->count());
    }
    private function shareRequest():Request{config(['session.driver'=>'database']);app('session')->forgetDrivers();$request=Request::create('/');$request->setLaravelSession(app('session')->driver());$request->session()->start();return $request;}
    private function unit(string $kind,string $text,string $item,?string $source,string $mapping,string $status='completed'):int{return DB::table('subtitle_units')->insertGetId(['lesson_id'=>$this->lesson,'model_session_id'=>$this->model,'provider_unit_id'=>$kind.':'.$item,'kind'=>$kind,'item_id'=>$item,'source_item_id'=>$source,'mapping'=>$mapping,'original_text'=>$text,'status'=>$status,'language'=>$kind==='source'?'en':'zh','start_sample'=>16000,'end_sample'=>32000,'timing_precision'=>'server_segment']);}
    private function exportOptions(string $format,string $version='current'):array{return ['format'=>$format,'layout'=>'bilingual','text_version'=>$version,'idempotency_key'=>(string)Str::uuid()];}
    private function failure(string $code,callable $call):void{try{$call();self::fail('Expected '.$code);}catch(Failure $error){self::assertSame($code,$error->errorCode);}}
}
