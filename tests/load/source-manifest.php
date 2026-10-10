<?php
declare(strict_types=1);
// Hash code only: never read dotenv, storage/config caches or private fixtures.
require dirname(__DIR__,2).'/vendor/autoload.php';
use Symfony\Component\Process\Process;
$root=realpath(dirname(__DIR__,2));$files=[];
foreach(['app','config','routes','database/migrations','tests']as$directory){
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS))as$file){
  if(!$file->isFile()||$file->isLink()||!in_array(strtolower($file->getExtension()),['php','mjs','ps1'],true))continue;
  $path=$file->getRealPath();if(!str_starts_with(str_replace('\\','/',$path),str_replace('\\','/',$root).'/'))throw new RuntimeException('source_path_not_allowed');
  $relative=str_replace('\\','/',substr($path,strlen($root)+1));$files[$relative]=hash_file('sha256',$path);
 }
}
foreach(['bootstrap/app.php','bootstrap/providers.php','composer.json','composer.lock','bin/gateway.php','bin/bootstrap.php','public/router.php']as$relative)if(is_file($root.'/'.$relative))$files[$relative]=hash_file('sha256',$root.'/'.$relative);
ksort($files);$head=null;$dirty=null;
try{$p=new Process(['git','rev-parse','HEAD'],$root);$p->setTimeout(5);$p->run();$value=trim($p->getOutput());if($p->isSuccessful()&&preg_match('/^[a-f0-9]{40}$/D',$value))$head=$value;$p=new Process(['git','status','--porcelain'],$root);$p->setTimeout(5);$p->run();if($p->isSuccessful())$dirty=trim($p->getOutput())!=='';}catch(Throwable){}
echo json_encode(['captured_at'=>gmdate(DATE_ATOM),'git_head'=>$head,'working_tree_dirty'=>$dirty,'scope'=>'application and load-profile code only; excludes dotenv, credentials, storage and config caches','sha256'=>$files],JSON_THROW_ON_ERROR).PHP_EOL;
