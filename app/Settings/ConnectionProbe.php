<?php
declare(strict_types=1);
namespace App\Settings;
use App\Storage\{AwsS3OssStorage,OssConfiguration};
use Aws\Exception\AwsException;
use GuzzleHttp\Client;
use Symfony\Component\Process\Process;

/** Only allowlisted OSS/Qwen configurations enter here; no raw diagnostics escape. */
final class ConnectionProbe {
 public function model(string $version):array {
  $process=new Process([PHP_BINARY,base_path('bin/probe-model-connection.php'),$version],base_path());$process->setTimeout(50);$process->run();
  foreach(explode("\n",$process->getOutput())as$line)if(str_starts_with($line,'PROBE_JSON:')){$report=json_decode(substr($line,11),true,16,JSON_THROW_ON_ERROR);return $report;}
  return ['classification'=>'real_qwen_connection_no_audio','status'=>'failed','steps'=>[['step'=>'connection','status'=>'failed','error_code'=>'model_connection_probe_failed']]];
 }
 public function oss(array $values):array {
  $report=['classification'=>'real_oss_s3','status'=>'running','steps'=>[]];$storage=new AwsS3OssStorage(new OssConfiguration($values));$keys=[];$temporary=null;$stepName='configuration';
  $step=function(string $name,callable $fn)use(&$report,&$stepName){$stepName=$name;$start=hrtime(true);$result=$fn();$report['steps'][]=['step'=>$name,'status'=>'passed','elapsed_ms'=>(int)((hrtime(true)-$start)/1000000)];return $result;};
  try{
   $base='_connection-tests/'.bin2hex(random_bytes(16));$keys=[$storage->configuration->objectKey($base.'/small.bin'),$storage->configuration->objectKey($base.'/multipart.bin')];$body=random_bytes(4096);$sha=hash('sha256',$body);
   $step('put',fn()=>$storage->put($keys[0],$body,'application/octet-stream',['sha256'=>$sha]));
   $step('get',function()use($storage,$keys,$sha){if(!hash_equals($sha,hash('sha256',$storage->read($keys[0]))))throw new \RuntimeException('checksum');});
   $step('head',function()use($storage,$keys,$sha){$h=$storage->head($keys[0]);if($h['bytes']!==4096||!hash_equals($sha,$h['sha256']??''))throw new \RuntimeException('head');});
   $http=new Client(['timeout'=>30,'connect_timeout'=>5,'allow_redirects'=>false,'http_errors'=>false,'verify'=>true]);$url=$storage->presignedGet($keys[0]);
   $step('signed_get',function()use($http,$url,$sha){$r=$http->get($url);if($r->getStatusCode()!==200||!hash_equals($sha,hash('sha256',(string)$r->getBody())))throw new \RuntimeException('signed');});
   $step('range',function()use($http,$url,$body){$r=$http->get($url,['headers'=>['Range'=>'bytes=32-95']]);if($r->getStatusCode()!==206||(string)$r->getBody()!==substr($body,32,64))throw new \RuntimeException('range');});
   $step('private',function()use($http,$url){if(!in_array($http->get(explode('?',$url,2)[0])->getStatusCode(),[401,403,404],true))throw new \RuntimeException('private');});
   $temporary=tempnam(storage_path('app'),'connection-probe-');if(!$temporary)throw new \RuntimeException('temporary');$large=str_repeat('OSS connection test ',500000);file_put_contents($temporary,$large);$largeSha=hash('sha256',$large);
   $step('multipart_put_head',fn()=>$storage->upload($keys[1],$temporary,'application/octet-stream',$largeSha));
   $step('multipart_get',function()use($storage,$keys,$largeSha){if(!hash_equals($largeSha,hash('sha256',$storage->read($keys[1]))))throw new \RuntimeException('multipart');});
   $step('delete',function()use($storage,$keys){foreach($keys as$key)$storage->delete($key);});
   $step('deleted_absent',function()use($storage,$keys){foreach($keys as$key){try{$storage->head($key);}catch(AwsException $e){if($e->getStatusCode()===404)continue;throw $e;}throw new \RuntimeException('delete');}});
   $report['status']='passed';
  }catch(\Throwable $e){$report['status']='failed';$entry=['step'=>$stepName,'status'=>'failed','error_code'=>'connection_step_failed','error_type'=>get_class($e)];if($e instanceof AwsException){$entry['http_status']=$e->getStatusCode();$entry['provider_code']=$e->getAwsErrorCode();}$report['steps'][]=$entry;}
  finally{foreach($keys as$key){try{$storage->delete($key);}catch(\Throwable){$report['status']='failed';$report['steps'][]=['step'=>'cleanup','status'=>'failed','error_code'=>'connection_cleanup_failed'];}}if(is_string($temporary)&&is_file($temporary))unlink($temporary);}
  return $report;
 }
}
