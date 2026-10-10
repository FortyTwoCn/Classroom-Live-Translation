<?php
require __DIR__.'/../../bin/bootstrap.php';
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Support\Str;
if(!app()->environment('testing')||config('database.connections.pgsql.database')!=='classroom_test')throw new RuntimeException('B fixtures require isolated classroom_test');
config(['hashing.bcrypt.rounds'=>4]);$suffix=bin2hex(random_bytes(6));$accounts=[];
foreach(['admin','member','forced']as$type){$student='0000-fixture-'.$type.'-'.$suffix;$password=$type==='forced'?mb_substr($student,-6):'Fixture-password-2026';$uid=DB::table('users')->insertGetId(['name'=>'Тест Ї Є '.$type,'student_id'=>$student,'password'=>Hash::make($password),'role'=>$type==='admin'?'admin':'user','must_change_password'=>$type==='forced']);$accounts[$type]=['id'=>$uid,'student_id'=>$student,'password'=>$password];}
$course=DB::table('courses')->insertGetId(['name'=>'SYNTHETIC CONTENT / 空气动力学 Ї','description'=>'Explicit fixture. No hardware audio or model call.']);
$private=DB::table('courses')->insertGetId(['name'=>'SYNTHETIC PRIVATE COURSE','guest_access'=>'link']);
$ended=DB::table('lessons')->insertGetId(['course_id'=>$course,'creator_id'=>$accounts['admin']['id'],'name'=>'SYNTHETIC 字幕导出 — Українська','target_language'=>'zh','status'=>'ended','ended_at'=>now(),'media_samples'=>160000,'idempotency_key'=>(string)Str::uuid()]);
$ready=DB::table('lessons')->insertGetId(['course_id'=>$course,'creator_id'=>$accounts['member']['id'],'recorder_user_id'=>$accounts['member']['id'],'name'=>'SYNTHETIC microphone fixture','target_language'=>'zh','status'=>'ready','idempotency_key'=>(string)Str::uuid()]);
$privateLesson=DB::table('lessons')->insertGetId(['course_id'=>$private,'creator_id'=>$accounts['admin']['id'],'name'=>'SYNTHETIC private historical lesson','target_language'=>'zh','status'=>'ended','ended_at'=>now(),'idempotency_key'=>(string)Str::uuid()]);
foreach([$ended,$ready,$privateLesson]as$lid)\App\Sharing\SharingService::ensure($lid);
$sid=(string)Str::uuid();DB::table('model_sessions')->insert(['id'=>$sid,'lesson_id'=>$ended,'base_sample'=>0,'model'=>'explicit-synthetic-content-no-model-call','region'=>'fixture','config'=>'{}','status'=>'finished','ended_at'=>now()]);$unitIds=[];
// Simulated provider supplies a valid segment range; all test reports label it synthetic.
foreach(['source'=>'Lift coefficient describes an airfoil. Ї Є Ґ','translation'=>'升力系数描述翼型。 Привіт І Ї Є Ґ']as$kind=>$text){$unitIds[$kind]=DB::table('subtitle_units')->insertGetId(['lesson_id'=>$ended,'model_session_id'=>$sid,'provider_unit_id'=>$kind.':fixture','kind'=>$kind,'item_id'=>$kind==='source'?'fixture-source':'fixture-translation','source_item_id'=>$kind==='translation'?'fixture-source':null,'original_text'=>$text,'status'=>'completed','language'=>$kind==='source'?'en':'zh','start_sample'=>16000,'end_sample'=>64000,'timing_precision'=>'server_segment','mapping'=>$kind==='source'?'source_identity':'explicit_previous_item_id']);}
echo json_encode(['classification'=>'SYNTHETIC_CONTENT_NO_MODEL_AUDIO','accounts'=>$accounts,'course_id'=>$course,'private_course_id'=>$private,'ended_lesson_id'=>$ended,'ready_lesson_id'=>$ready,'private_lesson_id'=>$privateLesson,'units'=>$unitIds],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
