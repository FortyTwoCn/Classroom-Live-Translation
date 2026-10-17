<?php
use App\Domain\{Access,Failure};
use App\Sharing\SharingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,RateLimiter,Route};
use App\Storage\AwsS3OssStorage;
$user=fn(Request $r)=>Access::user($r->session()->get('uid'));
foreach(['accounts','management','content','sharing','classrooms','reliability','glossary','usage']as$routeFile){if(is_file(__DIR__."/$routeFile.php"))require __DIR__."/$routeFile.php";}
Route::get('/api/courses',function(Request $r)use($user){
 $q=DB::table('courses')->where('enabled',true)->whereNull('deleted_at');$u=$user($r);if(!$u||$u->must_change_password)$q->where('guest_access','public');
 return ['courses'=>$q->select('courses.*')->selectRaw("(SELECT count(*) FROM lessons WHERE course_id=courses.id AND deleted_at IS NULL) AS lesson_count, (SELECT id FROM lessons WHERE course_id=courses.id AND status IN ('live','paused') AND deleted_at IS NULL LIMIT 1) AS active_lesson_id")->orderByRaw("EXISTS(SELECT 1 FROM lessons WHERE course_id=courses.id AND status IN ('live','paused') AND deleted_at IS NULL) DESC")->orderBy('sort_order')->orderBy('id')->limit(200)->get()];
});
Route::post('/api/courses',function(Request $r)use($user){$u=$user($r);Access::admin($u);$v=$r->validate(['name'=>'required|string|max:200','description'=>'sometimes|nullable|string|max:5000','target_language'=>'in:zh,en,uk','guest_access'=>'in:public,link']);return ['course'=>\App\Accounts\Management::course((int)$u->id,null,$v)];});
Route::get('/api/courses/{course}/lessons',function(Request $r,int $course)use($user){
 $c=DB::table('courses')->where('id',$course)->where('enabled',true)->whereNull('deleted_at')->first();if(!$c)throw new Failure('course_unavailable',404);
 $u=$user($r);if((!$u||$u->must_change_password)&&$c->guest_access!=='public')throw new Failure('forbidden',403);
 $v=$r->validate(['search'=>'nullable|string|max:200','sort'=>'in:name,created_at,duration','direction'=>'in:asc,desc','status'=>'in:all,ready,live,paused,ended','page'=>'integer|min:1|max:100000']);
 $q=DB::table('lessons')->where('course_id',$course)->whereNull('deleted_at');if(!empty($v['search']))$q->whereRaw('name ILIKE ?',['%'.addcslashes($v['search'],'%_\\').'%']);if(!empty($v['status'])&&$v['status']!=='all')$q->where('status',$v['status']);
 $total=(clone$q)->count();$sort=['name'=>'name','created_at'=>'created_at','duration'=>'media_samples'][$v['sort']??'created_at'];$direction=$v['direction']??'desc';$page=(int)($v['page']??1);
 return ['lessons'=>$q->orderBy($sort,$direction)->orderBy('id',$direction)->offset(($page-1)*30)->limit(30)->get()->map(fn($l)=>Access::publicLesson($l)),'total'=>$total,'page'=>$page,'per_page'=>30];
});
Route::post('/api/courses/{course}/lessons',function(Request $r,int $course)use($user){
 $u=$user($r);Access::member($u);$v=$r->validate(['name'=>'required|string|max:200','idempotency_key'=>'required|uuid']);if(trim($v['name'])==='')throw new Failure('validation_failed',422);
 return DB::transaction(function()use($u,$v,$course){
  $c=DB::table('courses')->where('id',$course)->where('enabled',true)->whereNull('deleted_at')->whereNull('archived_at')->lockForUpdate()->first();if(!$c)throw new Failure('course_unavailable',404);
  $e=DB::table('lessons')->where('idempotency_key',$v['idempotency_key'])->first();if($e){if((int)$e->creator_id!==(int)$u->id||(int)$e->course_id!==$course)throw new Failure('idempotency_conflict',409);return ['lesson'=>Access::publicLesson(Access::lesson((int)$e->id,$u))];}
  $active=DB::table('lessons')->where('course_id',$course)->where('status','<>','ended')->first();if($active)throw new Failure('active_lesson_exists',409,['lesson_id'=>$active->id]);
  $id=DB::table('lessons')->insertGetId(['course_id'=>$course,'creator_id'=>$u->id,'recorder_user_id'=>$u->id,'name'=>trim($v['name']),'target_language'=>$c->target_language,'idempotency_key'=>$v['idempotency_key']]);
  SharingService::ensure($id);Access::lessonEvent($id);return ['lesson'=>Access::publicLesson(Access::lesson($id,$u))];
 });
});
Route::patch('/api/lessons/{lesson}',function(Request $r,int $lesson)use($user){
 $u=$user($r);Access::member($u);$v=$r->validate(['name'=>'required|string|max:200','expected_version'=>'required|integer|min:1']);if(trim($v['name'])==='')throw new Failure('validation_failed',422);
 return DB::transaction(function()use($r,$u,$v,$lesson){DB::table('lessons')->where('id',$lesson)->lockForUpdate()->first();$l=Access::lesson($lesson,$u,$r->session()->getId());if((int)$l->version!==(int)$v['expected_version'])throw new Failure('lesson_version_conflict',409,['latest'=>Access::publicLesson($l)]);DB::table('lessons')->where('id',$lesson)->update(['name'=>trim($v['name']),'version'=>$l->version+1,'updated_at'=>now()]);DB::table('audit')->insert(['lesson_id'=>$lesson,'user_id'=>$u->id,'action'=>'lesson_renamed','details'=>json_encode(['before'=>$l->name,'after'=>trim($v['name'])],JSON_UNESCAPED_UNICODE)]);Access::lessonEvent($lesson);return ['lesson'=>Access::publicLesson(Access::lesson($lesson,$u))];});
});
Route::get('/api/lessons/{lesson}',function(Request $r,int $lesson)use($user){
 return DB::transaction(function()use($r,$lesson,$user){DB::table('lessons')->where('id',$lesson)->lockForUpdate()->first();$l=Access::lesson($lesson,$user($r),$r->session()->getId());$q=\App\Content\SubtitleText::visible(DB::table('subtitle_units')->where('lesson_id',$lesson)->whereNull('deleted_at'));if($r->filled('before_id'))$q->where('id','<',(int)$r->input('before_id'));
 $units=$q->orderByDesc('id')->limit(200)->get()->reverse()->values()->map(fn($u)=>Access::unit($u));
 return ['lesson'=>Access::publicLesson($l),'units'=>$units,'last_seq'=>(int)DB::table('lesson_events')->where('lesson_id',$lesson)->max('seq'),'issues'=>DB::table('recording_issues')->where('lesson_id',$lesson)->orderByDesc('id')->limit(100)->get()->map(fn($i)=>\App\Reliability\Recovery::issue($i))];});
});
Route::get('/api/lessons/{lesson}/subtitles',function(Request $r,int $lesson)use($user){
 Access::lesson($lesson,$user($r),$r->session()->getId());$v=$r->validate(['q'=>'nullable|string|max:200','before_id'=>'integer|min:1','around_id'=>'integer|min:1','at_sample'=>'integer|min:0|max:1382400000','page'=>'integer|min:1|max:100000']);$q=\App\Content\SubtitleText::visible(DB::table('subtitle_units')->where('lesson_id',$lesson)->whereNull('deleted_at'));
 if(!empty($v['q']))$q->whereRaw('COALESCE(current_text,original_text) ILIKE ?',['%'.addcslashes($v['q'],'%_\\').'%']);
 if(isset($v['at_sample'])){$pivot=(clone$q)->where('timing_precision','server_segment')->where('start_sample','<=',$v['at_sample'])->where('end_sample','>',$v['at_sample'])->orderBy('start_sample')->first();if(!$pivot)return ['units'=>[],'total'=>0,'page'=>1,'per_page'=>100,'has_more'=>false];$v['around_id']=$pivot->id;}
 if(isset($v['around_id'])){$pivot=(clone$q)->where('id',$v['around_id'])->first();if(!$pivot)throw new Failure('subtitle_unavailable',404);$ids=(clone$q)->where('id','<=',$pivot->id)->orderByDesc('id')->limit(40)->pluck('id')->merge((clone$q)->where('id','>',$pivot->id)->orderBy('id')->limit(40)->pluck('id'));$q->whereIn('id',$ids);}
 if(isset($v['before_id']))$q->where('id','<',$v['before_id']);$total=(clone$q)->count();$page=(int)($v['page']??1);$units=$q->orderByDesc('id')->offset(($page-1)*100)->limit(100)->get()->reverse()->values()->map(fn($u)=>Access::unit($u));
 return ['units'=>$units,'total'=>$total,'page'=>$page,'per_page'=>100,'has_more'=>$page*100<$total];
});
Route::post('/api/lessons/{lesson}/ticket',function(Request $r,int $lesson)use($user){
 $u=$user($r);Access::lesson($lesson,$u,$r->session()->getId());if(RateLimiter::tooManyAttempts('ticket:'.$r->session()->getId(),20))throw new Failure('rate_limited',429);RateLimiter::hit('ticket:'.$r->session()->getId(),60);
 $ticket=bin2hex(random_bytes(32));DB::table('ws_tickets')->insert(['hash'=>hash('sha256',$ticket),'lesson_id'=>$lesson,'user_id'=>$u?->id,'session_id'=>$r->session()->getId(),'expires_at'=>now()->addSeconds(30)]);return ['ticket'=>$ticket];
});
Route::get('/api/lessons/{lesson}/playback',function(Request $r,int $lesson)use($user){
 $l=Access::lesson($lesson,$user($r),$r->session()->getId());if($l->archive_status!=='playable')throw new Failure('recording_not_ready',409);
 $recording=DB::table('recordings')->where('lesson_id',$lesson)->where('status','playable')->first();if(!$recording)throw new Failure('recording_not_ready',409);
 try{$storage=\App\Storage\StorageRegistry::resolve($recording->storage_version);}catch(\Throwable){throw new Failure('storage_configuration_version_unavailable',503);}return ['url'=>$storage->presignedGet($recording->object_key),'expires_at'=>now()->addSeconds($storage->configuration->presignSeconds)->toIso8601String()];
});
Route::get('/{path?}',function(){$p=public_path('app.html');if(!is_file($p))return response('Build frontend: cd frontend && pnpm install && pnpm build',503);return response()->file($p);})->where('path','(?!api|up).*');

