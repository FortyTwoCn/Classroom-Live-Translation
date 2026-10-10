#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use App\Domain\Access;
use App\Jobs\TestConnection;
use App\Settings\Settings;
use App\Storage\StorageRegistry;
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

// This probe intentionally writes only to an isolated synthetic database.
// The normal TestConnection job creates/deletes its own two random cloud keys.
$database=DB::connection()->getDatabaseName();
if(!app()->environment('testing')||!preg_match('/^classroom_(?:disaster_[a-f0-9]{16}|integration_[a-f0-9]{12})$/D',$database))throw new RuntimeException('dedicated_synthetic_database_required');
if(config('queue.default')!=='database')throw new RuntimeException('real_database_queue_required');
$started=hrtime(true);$id=(string)Str::uuid();$queue='connection-probe-'.bin2hex(random_bytes(8));
$report=['classification'=>'REAL_OSS_DATABASE_QUEUE_ENCRYPTED_SETTINGS_TESTCONNECTION_SUBPROCESS','status'=>'running','job_class'=>TestConnection::class,'real_oss'=>true,'real_model'=>false,'physical_microphone_verified'=>false,'cloud_key_scope'=>'two newly generated _connection-tests keys only'];
try{
 $actorId=DB::table('users')->insertGetId(['student_id'=>'oss-probe-'.bin2hex(random_bytes(8)),'name'=>'Synthetic OSS connection test administrator','role'=>'admin','password'=>Hash::make(Str::random(48)),'must_change_password'=>false]);
 Access::admin(Access::user($actorId));$report['owner_admin_verified']=true;
 // Preserve the source classroom's frozen destination when there is no saved
 // OSS setting yet. The worker itself receives only the encrypted version ID.
 if(!Settings::version('oss')){
  $historical=DB::table('lessons')->whereNotNull('storage_version')->value('storage_version');
  if(!$historical)throw new RuntimeException('historical_storage_version_required');
  $cfg=StorageRegistry::configuration($historical);
  config(['classroom.oss'=>['endpoint'=>$cfg->endpoint,'region'=>$cfg->region,'bucket'=>$cfg->bucket,'key'=>$cfg->key,'secret'=>$cfg->secret,'token'=>$cfg->token,'prefix'=>$cfg->prefix,'presign_seconds'=>$cfg->presignSeconds]]);
 }
 $version=Settings::ensureVersion('oss');$values=Settings::byVersion($version,'oss');$row=DB::table('settings_versions')->where('id',$version)->first();
 $report['stored_encrypted_configuration']=isset($row->payload_encrypted)&&!str_contains($row->payload_encrypted,$values['secret'])&&!str_contains($row->payload_encrypted,$values['key']);
 if(!$report['stored_encrypted_configuration'])throw new RuntimeException('configuration_not_encrypted');
 DB::table('connection_tests')->insert(['id'=>$id,'owner_id'=>$actorId,'kind'=>'oss','settings_version'=>$version]);
 TestConnection::dispatch($id)->onQueue($queue);
 $report['queued_before_worker']=DB::table('jobs')->where('queue',$queue)->count();
 if($report['queued_before_worker']!==1)throw new RuntimeException('probe_job_not_queued');
 $worker=new Process([PHP_BINARY,base_path('artisan'),'queue:work','--queue='.$queue,'--once','--tries=1','--timeout=600'],base_path(),['APP_ENV'=>'testing','DB_DATABASE'=>$database,'LARAVEL_STORAGE_PATH'=>storage_path()]);
 $worker->setTimeout(650);$worker->run();
 $test=DB::table('connection_tests')->where('id',$id)->first();
 $report['worker_exit_code']=$worker->getExitCode();$report['status']=$test->status;
 $report['probe_report']=$test->report?json_decode($test->report,true,32,JSON_THROW_ON_ERROR):null;
 $report['failure_code']=$test->failure_code;
 $report['queued_after_worker']=DB::table('jobs')->where('queue',$queue)->count();
 $report['synthetic_database_retained']=true;
 if(!$worker->isSuccessful()||$report['queued_after_worker']!==0)$report['status']='failed';
}catch(Throwable $e){$report['status']='failed';$report['error_type']=get_class($e);if(preg_match('/^[a-z0-9_]+$/D',$e->getMessage()))$report['error_code']=$e->getMessage();}
$report['elapsed_seconds']=round((hrtime(true)-$started)/1e9,3);
$reportPath=base_path('reports/phase-c-real-oss-connection-queue.json');
file_put_contents($reportPath,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode(['status'=>$report['status'],'report_path'=>$reportPath],JSON_THROW_ON_ERROR).PHP_EOL;
exit($report['status']==='passed'?0:1);
