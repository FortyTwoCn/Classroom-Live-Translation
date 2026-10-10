<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use App\Storage\AwsS3OssStorage;
use Aws\{Result,S3\S3Client};
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Utils;
// SDK command handler is installed only in this guarded test process. No HTTP transport runs.
app()->bind(AwsS3OssStorage::class,function($app,$parameters){$cfg=$parameters['configuration'];$root=storage_path('app/load-oss');if(!is_dir($root))mkdir($root,0700,true);
 $client=new S3Client([...AwsS3OssStorage::clientOptions($cfg),'handler'=>function($cmd,$request)use($root){$name=$cmd->getName();$id=hash('sha256',$cmd['Bucket'].'/'.$cmd['Key']);$path=$root.'/'.$id;$meta=$path.'.json';$start=microtime(true);
  if($name==='PutObject'){file_put_contents($path,(string)$request->getBody());file_put_contents($meta,json_encode(['Metadata'=>$cmd['Metadata']??[],'ContentType'=>$cmd['ContentType']??'application/octet-stream']));$result=['ETag'=>'load-fixture'];}
  elseif($name==='HeadObject'){if(!is_file($path))throw new RuntimeException('load_object_missing');$result=['ContentLength'=>filesize($path),...json_decode(file_get_contents($meta),true)];}
  elseif($name==='GetObject'){$result=['Body'=>Utils::streamFor(fopen($path,'rb'))];}
  elseif($name==='CreateMultipartUpload'){$upload=bin2hex(random_bytes(16));file_put_contents($root.'/upload-'.$upload.'.json',json_encode(['Metadata'=>$cmd['Metadata']??[],'ContentType'=>$cmd['ContentType']??'application/octet-stream']));$result=['UploadId'=>$upload];}
  elseif($name==='UploadPart'){$upload=$cmd['UploadId'];if(!preg_match('/^[a-f0-9]{32}$/D',$upload))throw new RuntimeException('load_upload_id_invalid');file_put_contents($root.'/upload-'.$upload.'-'.$cmd['PartNumber'],(string)$request->getBody());$result=['ETag'=>'part-'.$cmd['PartNumber']];}
  elseif($name==='CompleteMultipartUpload'){$upload=$cmd['UploadId'];if(!preg_match('/^[a-f0-9]{32}$/D',$upload))throw new RuntimeException('load_upload_id_invalid');$out=fopen($path,'wb');foreach($cmd['MultipartUpload']['Parts']as$p){$in=fopen($root.'/upload-'.$upload.'-'.$p['PartNumber'],'rb');stream_copy_to_stream($in,$out);fclose($in);}fclose($out);copy($root.'/upload-'.$upload.'.json',$meta);$result=['ETag'=>'load-multipart'];}
  elseif($name==='AbortMultipartUpload'||$name==='DeleteObject'){$result=[];if($name==='DeleteObject'&&is_file($path)){unlink($path);if(is_file($meta))unlink($meta);}}
  else throw new RuntimeException('load_sdk_command_not_implemented');
  file_put_contents(storage_path('../upload-metrics.ndjson'),json_encode(['command'=>$name,'object_hash'=>$id,'duration_ms'=>(microtime(true)-$start)*1000,'at_ms'=>(int)round(microtime(true)*1000)])."\n",FILE_APPEND|LOCK_EX);
  return new FulfilledPromise(new Result($result));}]);return new AwsS3OssStorage($cfg,$client);
});
if(($argv[1]??null)==='recover-archive'){require __DIR__.'/archive-recovery.php';exit(0);}
$status=app(Illuminate\Contracts\Console\Kernel::class)->handle(new Symfony\Component\Console\Input\ArgvInput,new Symfony\Component\Console\Output\ConsoleOutput);
exit($status);
