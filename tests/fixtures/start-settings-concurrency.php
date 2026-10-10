<?php
declare(strict_types=1);
require __DIR__.'/../../bin/bootstrap.php';
use App\Domain\Access;
use App\Realtime\Gateway;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Workerman\{Worker,Timer};
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
$mode=$argv[1]??'';$directory=$argv[2]??'';
if(!app()->environment('testing')||getenv('START_SETTINGS_CONCURRENCY_PROFILE')!=='1'||!preg_match('/^classroom_integration_[a-f0-9]{12}$/D',DB::connection()->getDatabaseName()))throw new RuntimeException('isolated_concurrency_fixture_required');
if($mode==='setup'){
 $user=DB::table('users')->insertGetId(['student_id'=>'lock-'.Str::uuid(),'name'=>'Synthetic concurrency administrator','role'=>'admin','password'=>'fixture-unused','must_change_password'=>false]);
 $course=DB::table('courses')->insertGetId(['name'=>'EXPLICIT LOCK CONCURRENCY FIXTURE','guest_access'=>'public']);
 $lesson=DB::table('lessons')->insertGetId(['course_id'=>$course,'creator_id'=>$user,'recorder_user_id'=>$user,'name'=>'Synthetic concurrent start','target_language'=>'zh','idempotency_key'=>(string)Str::uuid()]);
 file_put_contents($directory.'/fixture.json',json_encode(['user'=>$user,'lesson'=>$lesson],JSON_THROW_ON_ERROR));exit;
}
$fixture=json_decode(file_get_contents($directory.'/fixture.json'),true,32,JSON_THROW_ON_ERROR);
$pid=(int)DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
try{
 if($mode==='settings'){
  file_put_contents($directory.'/settings.started.json',json_encode(['pid'=>$pid]));
  $conflicts=0;
  try{Settings::save('model',['workspace_id'=>'fixture-updated'],Settings::version('model'),Access::user($fixture['user']));}
  catch(\App\Domain\Failure $error){if($error->errorCode!=='settings_version_conflict')throw $error;$conflicts++;Settings::save('model',['workspace_id'=>'fixture-updated'],Settings::version('model'),Access::user($fixture['user']));}
  $report=['status'=>'passed','mode'=>$mode,'postgres_pid'=>$pid,'active_version'=>Settings::version('model'),'optimistic_version_conflicts'=>$conflicts];
 }elseif($mode==='gateway'){
  // Owned loopback listener: no real provider or shared mock server is contacted.
  $mock=stream_socket_server('tcp://127.0.0.1:0');if(!$mock)throw new RuntimeException('loopback_listener_failed');
  $mockAddress=stream_socket_get_name($mock,false);$mockPort=(int)substr($mockAddress,strrpos($mockAddress,':')+1);
  config(['classroom.qwen.endpoint'=>'ws://127.0.0.1:'.$mockPort.'/api-ws/v1/realtime']);
  $loop=new Select();Worker::$globalEvent=$loop;Timer::init($loop);
  $client=stream_socket_client('tcp://'.$mockAddress);$socket=stream_socket_accept($mock,2);if(!$client||!$socket)throw new RuntimeException('loopback_pair_failed');
  $connection=new TcpConnection($loop,$socket);$connection->context->key='fixture-concurrency-connection';
  $config=config('database.connections.pgsql');
  $observer=new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.DB::connection()->getDatabaseName(),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $observed=null;$hooked=false;
  DB::listen(function($query)use(&$hooked,&$observed,$observer,$directory){
   if($hooked||!str_contains($query->sql,'"users"')||!str_contains(strtolower($query->sql),'for update'))return;
   $hooked=true;file_put_contents($directory.'/gateway.row-locked.json',json_encode(['locked'=>true]));$deadline=microtime(true)+8;
   while(microtime(true)<$deadline){
    $path=$directory.'/settings.started.json';if(is_file($path)){$settings=json_decode(file_get_contents($path),true);$statement=$observer->prepare('SELECT wait_event_type,wait_event FROM pg_stat_activity WHERE pid=?');$statement->execute([(int)$settings['pid']]);$state=$statement->fetch(PDO::FETCH_ASSOC);if(($state['wait_event_type']??null)==='Lock'){$observed=$state['wait_event'];break;}}
    usleep(10000);
   }
   if($observed===null)throw new RuntimeException('concurrent_settings_lock_not_observed');
  });
  $gateway=new Gateway();$method=new ReflectionMethod($gateway,'start');
  $method->invoke($gateway,$connection,Access::lesson($fixture['lesson'],Access::user($fixture['user'])),Access::user($fixture['user']));
  $report=['status'=>'passed','mode'=>$mode,'postgres_pid'=>$pid,'settings_wait_event'=>$observed,'model_sessions'=>DB::table('model_sessions')->where('lesson_id',$fixture['lesson'])->count(),'lesson_model_version'=>DB::table('lessons')->where('id',$fixture['lesson'])->value('model_settings_version'),'cloud_requests'=>0,'provider_loop_run'=>false];
 }else throw new RuntimeException('concurrency_mode_invalid');
 file_put_contents($directory.'/'.$mode.'.result.json',json_encode($report,JSON_THROW_ON_ERROR));
}catch(Throwable $error){file_put_contents($directory.'/'.$mode.'.result.json',json_encode(['status'=>'failed','mode'=>$mode,'error_type'=>get_class($error),'code'=>$error instanceof \App\Domain\Failure?$error->errorCode:$error->getCode()]));exit(1);}
