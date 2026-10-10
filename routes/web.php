<?php
use App\Domain\Access;
use App\Domain\Failure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use App\Storage\AwsS3OssStorage;

$user=fn(Request $r)=>Access::user($r->session()->get('uid'));
$safe=fn(?object $u)=>$u?['id'=>$u->id,'name'=>$u->name,'role'=>$u->role,'must_change_password'=>(bool)$u->must_change_password]:null;
Route::get('/api/me',fn(Request $r)=>['user'=>$safe($user($r)),'csrf_token'=>csrf_token(),'ws_url'=>config('classroom.ws_url')]);
Route::post('/api/login',function(Request $r)use($safe){
 $v=$r->validate(['student_id'=>'required|string|max:100','password'=>'required|string|max:200']);
 $key='login:'.hash('sha256',$r->ip().'|'.$v['student_id']);
 if(RateLimiter::tooManyAttempts($key,5)) throw new Failure('login_rate_limited',429);
 RateLimiter::hit($key,60); $u=DB::table('users')->where('student_id',$v['student_id'])->first();
 if(!$u||$u->disabled||!Hash::check($v['password'],$u->password)) throw new Failure('invalid_credentials',401);
 RateLimiter::clear($key); $r->session()->regenerate(); $r->session()->put('uid',(int)$u->id);
 return ['user'=>$safe($u),'csrf_token'=>csrf_token()];
});
Route::post('/api/password',function(Request $r)use($user,$safe){
 $u=$user($r); if(!$u) throw new Failure('login_required',401);
 $v=$r->validate(['password'=>'required|string|min:10|max:200']);
 if($v['password']===$u->student_id||$v['password']===mb_substr($u->student_id,-6)) throw new Failure('password_predictable',422);
 if(!$u->must_change_password && !Hash::check((string)$r->input('current_password'),$u->password)) throw new Failure('invalid_credentials',401);
 DB::transaction(function()use($u,$v,$r){ DB::table('users')->where('id',$u->id)->update(['password'=>Hash::make($v['password']),'must_change_password'=>false,'updated_at'=>now()]); DB::table('ws_tickets')->where('user_id',$u->id)->delete(); DB::table('sessions')->where('id','<>',$r->session()->getId())->whereRaw("convert_from(decode(payload,'base64'),'UTF8') LIKE ?",['%"uid";i:'.$u->id.';%'])->delete(); });
 $r->session()->regenerate(); return ['user'=>$safe(Access::user((int)$u->id)),'csrf_token'=>csrf_token()];
});
Route::post('/api/logout',function(Request $r){ $r->session()->invalidate(); $r->session()->regenerateToken(); return ['csrf_token'=>csrf_token()]; });
Route::get('/api/courses',function(Request $r)use($user){
 $q=DB::table('courses')->where('enabled',true); $u=$user($r); if(!$u||$u->must_change_password)$q->where('guest_access','public');
 return ['courses'=>$q->select('courses.*')->selectRaw("(SELECT count(*) FROM lessons WHERE course_id=courses.id) AS lesson_count, (SELECT id FROM lessons WHERE course_id=courses.id AND status IN ('live','paused') LIMIT 1) AS active_lesson_id")->orderByRaw("EXISTS(SELECT 1 FROM lessons WHERE course_id=courses.id AND status IN ('live','paused')) DESC")->orderBy('id')->limit(100)->get()];
});
Route::post('/api/courses',function(Request $r)use($user){ Access::admin($user($r)); $v=$r->validate(['name'=>'required|string|max:200','target_language'=>'in:zh,en,uk','guest_access'=>'in:public,link']); return ['course'=>DB::table('courses')->find(DB::table('courses')->insertGetId($v))]; });
Route::get('/api/courses/{course}/lessons',function(Request $r,int $course)use($user){
 $c=DB::table('courses')->where('id',$course)->where('enabled',true)->first(); if(!$c)throw new Failure('course_unavailable',404);
 $u=$user($r); if((!$u||$u->must_change_password)&&$c->guest_access!=='public')throw new Failure('forbidden',403);
 $q=DB::table('lessons')->where('course_id',$course)->orderByDesc('created_at')->orderByDesc('id')->limit(50); return ['lessons'=>$q->get()->map(fn($l)=>Access::publicLesson($l))];
});
Route::post('/api/courses/{course}/lessons',function(Request $r,int $course)use($user){
 $u=$user($r); Access::member($u); $v=$r->validate(['name'=>'required|string|max:200','idempotency_key'=>'required|uuid']);
 return DB::transaction(function()use($u,$v,$course){
  $c=DB::table('courses')->where('id',$course)->where('enabled',true)->lockForUpdate()->first(); if(!$c)throw new Failure('course_unavailable',404);
  $e=DB::table('lessons')->where('idempotency_key',$v['idempotency_key'])->first(); if($e){if((int)$e->creator_id!==(int)$u->id||(int)$e->course_id!==$course)throw new Failure('idempotency_conflict',409); return ['lesson'=>Access::publicLesson(Access::lesson((int)$e->id,$u))];}
  $active=DB::table('lessons')->where('course_id',$course)->where('status','<>','ended')->first(); if($active)throw new Failure('active_lesson_exists',409,['lesson_id'=>$active->id]);
  $id=DB::table('lessons')->insertGetId(['course_id'=>$course,'creator_id'=>$u->id,'recorder_user_id'=>$u->id,'name'=>$v['name'],'target_language'=>$c->target_language,'idempotency_key'=>$v['idempotency_key']]);
  Access::lessonEvent($id); return ['lesson'=>Access::publicLesson(Access::lesson($id,$u))];
 });
});
Route::get('/api/lessons/{lesson}',function(Request $r,int $lesson)use($user){
 return DB::transaction(function()use($r,$lesson,$user){DB::table('lessons')->where('id',$lesson)->lockForUpdate()->first();$l=Access::lesson($lesson,$user($r)); $q=DB::table('subtitle_units')->where('lesson_id',$lesson); if($r->filled('before_id'))$q->where('id','<',(int)$r->input('before_id'));
 $units=$q->orderByDesc('id')->limit(200)->get()->reverse()->values()->map(fn($u)=>Access::unit($u));
 return ['lesson'=>Access::publicLesson($l),'units'=>$units,'last_seq'=>(int)DB::table('lesson_events')->where('lesson_id',$lesson)->max('seq')];});
});
Route::post('/api/lessons/{lesson}/ticket',function(Request $r,int $lesson)use($user){
 $u=$user($r); Access::lesson($lesson,$u); if(RateLimiter::tooManyAttempts('ticket:'.$r->session()->getId(),20))throw new Failure('rate_limited',429); RateLimiter::hit('ticket:'.$r->session()->getId(),60);
 $ticket=bin2hex(random_bytes(32)); DB::table('ws_tickets')->insert(['hash'=>hash('sha256',$ticket),'lesson_id'=>$lesson,'user_id'=>$u?->id,'session_id'=>$r->session()->getId(),'expires_at'=>now()->addSeconds(30)]); return ['ticket'=>$ticket];
});
Route::get('/api/lessons/{lesson}/playback',function(Request $r,int $lesson)use($user){
 $l=Access::lesson($lesson,$user($r)); if($l->archive_status!=='playable')throw new Failure('recording_not_ready',409);
 $recording=DB::table('recordings')->where('lesson_id',$lesson)->where('status','playable')->first(); if(!$recording)throw new Failure('recording_not_ready',409);
 $storage=app(AwsS3OssStorage::class); if($recording->storage_version!==$storage->configuration->version())throw new Failure('storage_configuration_version_unavailable',503); return ['url'=>$storage->presignedGet($recording->object_key),'expires_at'=>now()->addSeconds(config('classroom.oss.presign_seconds'))->toIso8601String()];
});
Route::get('/{path?}',function(){ $p=public_path('app.html'); if(!is_file($p))return response('Build frontend: cd frontend && pnpm install && pnpm build',503); return response()->file($p); })->where('path','(?!api|up).*');
