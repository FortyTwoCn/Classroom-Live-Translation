<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Domain\Access;
use App\Exports\{ExportService,SubtitleFormatter};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{Cache,DB,Queue};
use App\Jobs\{BuildSubtitleExport,PurgeLesson};
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;
final class FinalConcurrencyTest extends TestCase
{
 use DatabaseTransactions;
 public function test_cleanup_during_zero_timed_export_cannot_resurrect_expired_task():void
 {
  Queue::fake();$userId=DB::table('users')->insertGetId(['student_id'=>'expiry-'.Str::uuid(),'name'=>'Synthetic expiry user','role'=>'user','password'=>'unused','must_change_password'=>false]);$user=Access::user($userId);
  $course=DB::table('courses')->insertGetId(['name'=>'Synthetic expiry course','guest_access'=>'public']);$lesson=DB::table('lessons')->insertGetId(['course_id'=>$course,'creator_id'=>$userId,'name'=>'Synthetic expiry lesson','target_language'=>'zh','idempotency_key'=>(string)Str::uuid()]);
  $sid=(string)Str::uuid();DB::table('model_sessions')->insert(['id'=>$sid,'lesson_id'=>$lesson,'base_sample'=>0,'model'=>'explicit-fixture','config'=>'{}','status'=>'finished']);
  DB::table('subtitle_units')->insert(['lesson_id'=>$lesson,'model_session_id'=>$sid,'provider_unit_id'=>'fixture-untimed','kind'=>'source','original_text'=>'Synthetic untimed source','status'=>'completed']);
  $service=new ExportService();$task=$service->create($lesson,$user,['format'=>'srt','layout'=>'source','text_version'=>'original','idempotency_key'=>(string)Str::uuid()]);$injected=false;
  DB::listen(function($query)use(&$injected,$task,$service){if($injected||!str_contains(strtolower($query->sql),'select')||!str_contains($query->sql,'"subtitle_export_rows"'))return;$injected=true;DB::table('subtitle_exports')->where('id',$task['id'])->update(['expires_at'=>now()->subSecond()]);$service->cleanup();});
  $service->build($task['id'],new SubtitleFormatter());
  self::assertTrue($injected);self::assertSame('expired',DB::table('subtitle_exports')->where('id',$task['id'])->value('status'));self::assertNull(DB::table('subtitle_exports')->where('id',$task['id'])->value('file_path'));self::assertSame(0,DB::table('subtitle_export_rows')->where('export_id',$task['id'])->count());
 }
 public function test_actual_start_and_settings_save_serialize_on_two_postgres_processes():void
 {
  self::assertSame('classroom_test',DB::connection()->getDatabaseName());$suffix=bin2hex(random_bytes(6));$database='classroom_integration_'.$suffix;
  $configuration=config('database.connections.pgsql');$server=new \PDO('pgsql:host='.$configuration['host'].';port='.$configuration['port'].';dbname=postgres',$configuration['username'],$configuration['password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);$owner='"'.str_replace('"','""',$configuration['username']).'"';$server->exec('CREATE DATABASE '.$database.' OWNER '.$owner);
  $workspace=dirname(dirname(base_path()));$directory=$workspace.'/work/start-settings-'.$suffix;foreach(['','/storage/app/recordings','/storage/framework/cache/data','/storage/framework/views','/storage/logs']as$folder)if(!is_dir($directory.$folder))mkdir($directory.$folder,0700,true);
  $environment=['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(str_repeat('t',32)),'DB_DATABASE'=>$database,'LARAVEL_STORAGE_PATH'=>$directory.'/storage','START_SETTINGS_CONCURRENCY_PROFILE'=>'1','QWEN_API_KEY'=>'explicit-concurrency-mock-key','QWEN_WORKSPACE_ID'=>'fixture','QWEN_REGION'=>'cn-beijing','QWEN_ALLOW_TEST_ENDPOINT'=>'true','QWEN_WS_ENDPOINT'=>'ws://127.0.0.1:65534/api-ws/v1/realtime','OSS_ENDPOINT'=>'https://s3.oss-cn-hangzhou.aliyuncs.com','OSS_REGION'=>'cn-hangzhou','OSS_BUCKET'=>'concurrency-fixture','OSS_ACCESS_KEY_ID'=>'explicit-concurrency-mock-key','OSS_ACCESS_KEY_SECRET'=>'explicit-concurrency-mock-secret','OSS_SESSION_TOKEN'=>'','OSS_PREFIX'=>'concurrency-fixture'];
  $invoke=function(array $arguments)use($environment){$process=new Process([PHP_BINARY,...$arguments],base_path(),$environment);$process->setTimeout(20);return $process;};
  $invoke(['artisan','migrate','--force'])->mustRun();$invoke(['tests/fixtures/start-settings-concurrency.php','setup',$directory])->mustRun();
  $gateway=$invoke(['tests/fixtures/start-settings-concurrency.php','gateway',$directory]);$settings=$invoke(['tests/fixtures/start-settings-concurrency.php','settings',$directory]);
  try{$gateway->start();$deadline=microtime(true)+8;while(!is_file($directory.'/gateway.row-locked.json')){if(!$gateway->isRunning()||microtime(true)>$deadline)throw new \RuntimeException('Gateway fixture actor lock was not reached');usleep(10000);}$settings->start();$gateway->wait();$settings->wait();
   $first=json_decode(file_get_contents($directory.'/gateway.result.json'),true,32,JSON_THROW_ON_ERROR);$second=json_decode(file_get_contents($directory.'/settings.result.json'),true,32,JSON_THROW_ON_ERROR);
   self::assertTrue($gateway->isSuccessful(),json_encode($first));self::assertTrue($settings->isSuccessful(),json_encode($second));self::assertSame('advisory',$first['settings_wait_event']);self::assertNotSame($first['postgres_pid'],$second['postgres_pid']);self::assertSame(1,$first['model_sessions']);self::assertNotSame($first['lesson_model_version'],$second['active_version']);
   file_put_contents(base_path('reports/start-settings-postgresql-concurrency.json'),json_encode(['classification'=>'REAL_POSTGRESQL_TWO_PROCESSES_ACTUAL_GATEWAY_START_SETTINGS_SAVE_MOCK_CONFIG','status'=>'passed','gateway'=>$first,'settings'=>$second,'real_model'=>false,'real_oss'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
  }finally{if($gateway->isRunning())$gateway->stop(1);if($settings->isRunning())$settings->stop(1);}
 }
 public function test_real_worker_exports_after_six_shared_mutex_releases_without_failure():void
 {
  $fixture=$this->queueFixture(true);$manager=Queue::getFacadeRoot();Queue::fake();
  try{$service=new ExportService();$task=$service->create($fixture['lesson'],$fixture['user'],['format'=>'vtt','layout'=>'source','text_version'=>'original','idempotency_key'=>(string)Str::uuid()]);}finally{Queue::swap($manager);}
  $job=new BuildSubtitleExport($task['id']);$name='fixture-export-lock-'.Str::uuid();$id=Queue::connection('database')->push($job,'',$name);$mutex=$job->middleware()[1];$lock=Cache::lock($mutex->getLockKey($job),7500);self::assertTrue($lock->get());$path=null;
  try{$id=$this->holdAndReleaseThroughWorker($name,$id,fn()=>self::assertSame('queued',DB::table('subtitle_exports')->where('id',$task['id'])->value('status')));$lock->release();app('queue.worker')->runNextJob('database',$name,new WorkerOptions(sleep:0,maxTries:3));
   self::assertSame('ready',DB::table('subtitle_exports')->where('id',$task['id'])->value('status'));self::assertFalse(DB::table('jobs')->where('id',$id)->exists());$file=$service->file($task['id'],$fixture['user']);$path=$file['path'];self::assertStringStartsWith('WEBVTT',file_get_contents($path));self::assertStringContainsString('Synthetic queued source',file_get_contents($path));
  }finally{$lock->release();$this->travelBack();if($path&&is_file($path))unlink($path);if($path&&is_dir(dirname($path)))rmdir(dirname($path));}
 }
 public function test_real_worker_purges_after_six_shared_mutex_releases_without_failure():void
 {
  $fixture=$this->queueFixture(false);DB::table('lessons')->where('id',$fixture['lesson'])->update(['deleted_at'=>now(),'purge_status'=>'queued']);$job=new PurgeLesson($fixture['lesson']);$name='fixture-purge-lock-'.Str::uuid();$id=Queue::connection('database')->push($job,'',$name);$mutex=$job->middleware()[0];$lock=Cache::lock($mutex->getLockKey($job),7500);self::assertTrue($lock->get());
  try{$id=$this->holdAndReleaseThroughWorker($name,$id,fn()=>self::assertSame('queued',DB::table('lessons')->where('id',$fixture['lesson'])->value('purge_status')));$lock->release();app('queue.worker')->runNextJob('database',$name,new WorkerOptions(sleep:0,maxTries:4));self::assertSame('purged',DB::table('lessons')->where('id',$fixture['lesson'])->value('purge_status'));self::assertFalse(DB::table('jobs')->where('id',$id)->exists());self::assertSame(0,DB::table('lesson_events')->where('lesson_id',$fixture['lesson'])->count());}
  finally{$lock->release();$this->travelBack();}
 }
 private function holdAndReleaseThroughWorker(string $queue,int $id,callable $assertPending):int
 {
  for($attempt=0;$attempt<6;$attempt++){app('queue.worker')->runNextJob('database',$queue,new WorkerOptions(sleep:0,maxTries:3));$pending=DB::table('jobs')->where('queue',$queue)->first();self::assertNotNull($pending);$id=(int)$pending->id;self::assertSame(0,DB::table('failed_jobs')->where('queue',$queue)->count());$assertPending();$this->travel(16)->seconds();}self::assertSame(6,(int)DB::table('jobs')->where('id',$id)->value('attempts'));return $id;
 }
 private function queueFixture(bool $subtitles):array
 {
  $uid=DB::table('users')->insertGetId(['student_id'=>'queue-'.Str::uuid(),'name'=>'Synthetic queue owner','role'=>'user','password'=>'unused','must_change_password'=>false]);$course=DB::table('courses')->insertGetId(['name'=>'Synthetic queue course','guest_access'=>'public']);$lesson=DB::table('lessons')->insertGetId(['course_id'=>$course,'creator_id'=>$uid,'name'=>'Synthetic queue lesson','status'=>'ended','target_language'=>'zh','media_samples'=>$subtitles?16000:0,'idempotency_key'=>(string)Str::uuid()]);
  if($subtitles){$sid=(string)Str::uuid();DB::table('model_sessions')->insert(['id'=>$sid,'lesson_id'=>$lesson,'base_sample'=>0,'model'=>'fixture','config'=>'{}','status'=>'finished']);DB::table('subtitle_units')->insert(['lesson_id'=>$lesson,'model_session_id'=>$sid,'provider_unit_id'=>'fixture-queued','kind'=>'source','original_text'=>'Synthetic queued source','status'=>'completed','start_sample'=>0,'end_sample'=>16000,'timing_precision'=>'server_segment']);}
  return ['lesson'=>$lesson,'user'=>Access::user($uid)];
 }
}
