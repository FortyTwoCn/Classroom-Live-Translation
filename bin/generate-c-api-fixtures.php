#!/usr/bin/env php
<?php
declare(strict_types=1);
/** PostgreSQL fixture only, rolled back; never sends model/OSS requests. */
putenv('APP_ENV=testing');putenv('DB_DATABASE=classroom_test');$_ENV['APP_ENV']='testing';$_ENV['DB_DATABASE']='classroom_test';
$app=require __DIR__.'/bootstrap.php';
use App\Domain\Access;
use App\Glossary\Glossary;
use App\Reliability\Usage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
if(DB::connection()->getDriverName()!=='pgsql'||DB::connection()->getDatabaseName()!=='classroom_test')throw new \RuntimeException('Fixture generator requires classroom_test PostgreSQL.');
DB::beginTransaction();
try{
    $uid=DB::table('users')->insertGetId(['student_id'=>'fixture-'.Str::uuid(),'name'=>'Synthetic administrator','password'=>'unused','role'=>'admin','must_change_password'=>false]);$actor=Access::user($uid);
    $course=DB::table('courses')->insertGetId(['name'=>'Synthetic aerodynamics']);$lesson=DB::table('lessons')->insertGetId(['course_id'=>$course,'creator_id'=>$uid,'name'=>'Synthetic lesson','target_language'=>'zh','idempotency_key'=>(string)Str::uuid()]);
    $glossary=new Glossary;$saved=$glossary->save($course,null,$actor,['source_language'=>'en','target_language'=>'zh','source_text'=>'airfoil','target_text'=>'翼型','note'=>'Synthetic mapping','enabled'=>true],1);
    $glossary->save($course,null,$actor,['source_language'=>'en','target_language'=>'uk','source_text'=>'airfoil','target_text'=>'аеродинамічний профіль','note'=>'Synthetic mapping','enabled'=>true],2);
    $sid=(string)Str::uuid();DB::table('model_sessions')->insert(['id'=>$sid,'lesson_id'=>$lesson,'base_sample'=>0,'model'=>'fixture_not_qwen','region'=>'cn-beijing','config'=>'{}','status'=>'finished','created_at'=>now()->subSeconds(10),'ended_at'=>now()]);
    Usage::recordEvent($lesson,$sid,['type'=>'usage','response_id'=>'fixture-response','provider_event_id'=>'fixture-event','official'=>true,'status'=>'completed','usage'=>['total_tokens'=>211,'input_tokens'=>166,'output_tokens'=>45,'input_tokens_details'=>['audio_tokens'=>119,'text_tokens'=>47],'output_tokens_details'=>['text_tokens'=>45]]]);
    $limits=['max_lesson_seconds'=>5400,'max_daily_audio_seconds'=>32400,'warning_ratio'=>0.8];
    DB::table('daily_audio_usage')->updateOrInsert(['day'=>now()->utc()->format('Y-m-d')],['sample_count'=>0]);
    for($i=0;$i<10;$i++){$row=DB::table('lessons')->where('id',$lesson)->lockForUpdate()->first();Usage::acceptSamples($row,1600,$limits);DB::table('lessons')->where('id',$lesson)->increment('media_samples',1600);}
    $prices=[];foreach(['audio_input'=>'8.50','text_input'=>'2.00','text_output'=>'10.70']as $item=>$value)$prices[]=['region'=>'cn-beijing','currency'=>'CNY','item'=>$item,'unit_price'=>$value,'effective_at'=>'2026-01-01T00:00:00Z','source_url'=>'https://example.com/fictional-fixture-prices','verified_at'=>'2026-10-09'];
    $data=['test_kind'=>'synthetic_api_fixture','model_called'=>false,'oss_called'=>false,'provider_usage_is_fixture'=>true,'prices_are_fictional'=>true,'database_changes'=>'rolled_back','glossary_list'=>$glossary->listing($course,$actor),'glossary_saved'=>$saved,'glossary_snapshot'=>Glossary::snapshot($lesson,$course,'zh'),'usage_without_prices'=>Usage::summary($lesson,[]),'usage_with_fictional_prices'=>Usage::summary($lesson,$prices),'fictional_price_fields'=>Usage::validatePrices($prices)];
    foreach(['usage_without_prices','usage_with_fictional_prices']as $key)$data[$key]['audio']['limits']=$limits+['max_active_lessons'=>3];
    $path=__DIR__.'/../docs/examples/C-api-fixtures.json';if(file_put_contents($path,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL)===false)throw new \RuntimeException('Fixture file failed.');fwrite(STDOUT,'Synthetic C API fixture saved; database rolled back.'.PHP_EOL);
}finally{DB::rollBack();}
