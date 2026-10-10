#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use App\Jobs\TestConnection;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
if(!app()->environment('testing')||!preg_match('/^classroom_integration_[a-f0-9]+$/D',DB::connection()->getDatabaseName()))throw new RuntimeException('Dedicated integration database required.');
if(config('queue.default')!=='database')throw new RuntimeException('Real database queue required.');
$started=microtime(true);$id=(string)Str::uuid();
$actor=DB::table('users')->insertGetId(['student_id'=>'connection-'.bin2hex(random_bytes(6)),'name'=>'Synthetic connection test administrator','role'=>'admin','password'=>'fixture-unused','must_change_password'=>false]);
$version=Settings::ensureVersion('model');
DB::table('connection_tests')->insert(['id'=>$id,'owner_id'=>$actor,'kind'=>'model','settings_version'=>$version]);
TestConnection::dispatch($id);
$queued=DB::table('jobs')->where('queue','maintenance')->count();
$worker=new Process([PHP_BINARY,base_path('artisan'),'queue:work','--queue=maintenance','--once','--tries=1','--timeout=55'],base_path());$worker->setTimeout(65);$worker->run();
$row=DB::table('connection_tests')->where('id',$id)->first();
$report=['classification'=>'REAL_QWEN_DATABASE_QUEUE_TESTCONNECTION_SUBPROCESS_SELECT_NO_AUDIO','status'=>$row->status,'elapsed_seconds'=>round(microtime(true)-$started,3),'queued_before_worker'=>$queued,'worker_exit_code'=>$worker->getExitCode(),'job_class'=>TestConnection::class,'probe_report'=>json_decode($row->report,true,64,JSON_THROW_ON_ERROR),'failure_code'=>$row->failure_code,'real_model'=>true,'audio_samples'=>0,'physical_microphone_verified'=>false];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
exit($row->status==='passed'&&$worker->isSuccessful()?0:1);
