<?php
require __DIR__.'/bootstrap.php';
use App\Translation\QwenConfig;
use App\Storage\OssConfiguration;
$report=['secrets_printed'=>false];$q=QwenConfig::fromLaravel();
try{$q->validate();$report['qwen']=['configuration'=>'valid','real_test'=>'not_run'];}catch(Throwable $e){$report['qwen']=['configuration'=>'invalid','code'=>$e->getMessage(),'missing'=>$q->missing()];}
try{new OssConfiguration;$report['oss']=['configuration'=>'valid','real_test'=>'not_run'];}catch(Throwable $e){$report['oss']=['configuration'=>'invalid','code'=>$e->getMessage(),'missing'=>OssConfiguration::missing(config('classroom.oss'))];}
$report['app_key_configured']=(bool)config('app.key');$report['postgresql_driver']=config('database.default');
echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
exit(($report['qwen']['configuration']==='valid'&&$report['oss']['configuration']==='valid'&&$report['app_key_configured'])?0:2);
