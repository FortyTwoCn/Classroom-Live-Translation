#!/usr/bin/env php
<?php
require __DIR__.'/bootstrap.php';
use App\Settings\Settings;use App\Translation\{QwenConfig,QwenSession};use Workerman\{Worker,Timer};use Workerman\Events\Select;
$version=$argv[1]??'';if(!Illuminate\Support\Str::isUuid($version))exit(2);
$report=['classification'=>'real_qwen_connection_no_audio','status'=>'failed','steps'=>[],'audio_samples'=>0];
try{$config=QwenConfig::fromArray(Settings::byVersion($version,'model'),app()->environment('testing'));$config->validate();}catch(Throwable){echo 'PROBE_JSON:'.json_encode($report).PHP_EOL;exit(2);}
$loop=new Select();Worker::$globalEvent=$loop;Timer::init($loop);
$start=function()use($config,&$report,$loop){
 $peer=null;$peer=new QwenSession($config,'zh',function(array $e){},function(string $state)use(&$peer,&$report,$loop){if($state==='ready'){$report['steps'][]=['step'=>'session_updated_text','status'=>'passed'];$peer->finish();}if($state==='finished'){$report['status']='passed';$report['steps'][]=['step'=>'session_finished','status'=>'passed'];$loop->stop();}if(in_array($state,['failed','closed']))$loop->stop();});
 Timer::add(40,fn()=>$loop->stop(),[],false);$peer->connect();
};$loop->setErrorHandler(fn()=>$loop->stop());$start();$loop->run();echo 'PROBE_JSON:'.json_encode($report,JSON_THROW_ON_ERROR).PHP_EOL;
