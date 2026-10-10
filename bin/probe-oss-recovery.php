#!/usr/bin/env php
<?php
declare(strict_types=1);
use App\Domain\AudioStore;
use App\Jobs\{UploadChunk,ArchiveLesson};
use App\Storage\{OssConfiguration,StorageRegistry,SegmentRecovery,PcmArchiveAssembler,RecordingPaths};
use Illuminate\Support\Facades\{DB,Hash,Queue};
use Illuminate\Support\Str;
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$values=config('classroom.oss');$report=['test_type'=>'real_oss_archive_recovery','audio'=>'synthetic_440hz_10.1s_no_model_no_device','status'=>'running','started_at'=>gmdate(DATE_ATOM),'steps'=>[]];$missing=OssConfiguration::missing($values);
if($missing){$report['status']='unverified';$report['reason']='missing_configuration';$report['missing_fields']=$missing;echo json_encode($report,JSON_PRETTY_PRINT)."\n";exit(2);}
if(!is_file(config('classroom.ffmpeg'))||!is_file(config('classroom.ffprobe'))){$report['status']='unverified';$report['reason']='ffmpeg_unavailable';echo json_encode($report,JSON_PRETTY_PRINT)."\n";exit(2);}
$store=null;$keys=[];$files=[];$lessonId=null;$failed=false;$step='configuration';
$run=static function(string $name,callable $fn)use(&$report,&$step){$step=$name;$at=hrtime(true);$value=$fn();$report['steps'][]=['step'=>$name,'status'=>'passed','elapsed_ms'=>(int)((hrtime(true)-$at)/1000000)];return $value;};
DB::beginTransaction();Queue::fake();
try{
 $values['prefix']=trim(($values['prefix']??'classroom').'/_connection-tests/recovery-'.bin2hex(random_bytes(16)),'/');$version=$run('register_encrypted_private_test_version',fn()=>StorageRegistry::register($values));$store=StorageRegistry::resolve($version);
 $uid=DB::table('users')->insertGetId(['name'=>'Isolated recovery probe','student_id'=>'REC'.Str::random(24),'password'=>Hash::make(bin2hex(random_bytes(24))),'role'=>'user','must_change_password'=>false]);$cid=DB::table('courses')->insertGetId(['name'=>'Isolated recovery probe']);$lessonId=DB::table('lessons')->insertGetId(['name'=>'Synthetic source only','course_id'=>$cid,'creator_id'=>$uid,'target_language'=>'zh','status'=>'live','recorder_user_id'=>$uid,'recorder_connection'=>'probe','recorder_epoch'=>1,'lease_until'=>now()->addMinutes(5),'idempotency_key'=>(string)Str::uuid(),'storage_version'=>$version]);
 $expected=$run('persist_101_frames_durable_and_group',function()use($lessonId,$uid){$source='';for($frame=0;$frame<101;$frame++){$pcm='';for($i=0;$i<1600;$i++)$pcm.=pack('v',(int)round(7000*sin(2*M_PI*440*($frame*1600+$i)/16000))&0xffff);(new AudioStore)->accept($lessonId,$uid,'probe',1,$frame,$pcm);$source.=$pcm;}if(DB::table('audio_chunks')->where('lesson_id',$lessonId)->count()!==2)throw new RuntimeException('probe_chunk_count');return hash('sha256',$source);});
 $chunks=DB::table('audio_chunks')->where('lesson_id',$lessonId)->orderBy('id')->get();foreach($chunks as$c)$keys[]=$store->configuration->objectKey('lessons/'.$lessonId.'/chunks/'.$c->epoch.'/'.$c->group_sequence.'.pcm');$keys[]=$store->configuration->objectKey('lessons/'.$lessonId.'/archive/original.m4a');
 $run('real_upload_two_lossless_chunks',function()use($chunks){foreach($chunks as$c)(new UploadChunk($c->id))->handle(new SegmentRecovery);});
 $segments=DB::table('audio_segments')->where('lesson_id',$lessonId)->get();foreach($segments as$s)$files[]=$s->local_path;foreach(DB::table('audio_chunks')->where('lesson_id',$lessonId)->get()as$c)$files[]=$c->local_path;
 $run('simulate_loss_only_of_new_probe_local_files',function()use($files){foreach($files as$file)if(is_file($file))unlink(RecordingPaths::existing($file));});
 $run('change_active_prefix_without_changing_lesson_reference',function()use($values,$version,$lessonId){$next=[...$values,'prefix'=>$values['prefix'].'/new-default'];StorageRegistry::register($next);config(['classroom.oss'=>$next]);if(DB::table('lessons')->find($lessonId)->storage_version!==$version)throw new RuntimeException('probe_version_changed');});
 DB::table('lessons')->where('id',$lessonId)->update(['status'=>'ended']);
 $run('real_download_recover_ffmpeg_archive_rebuild_and_upload',fn()=>(new ArchiveLesson($lessonId,true))->handle(new PcmArchiveAssembler,new SegmentRecovery));
 $dir=RecordingPaths::archiveDirectory($lessonId);$files[]=$dir.'/input.pcm';$files[]=$dir.'/manifest.ndjson';$files[]=$dir.'/original.m4a';
 $run('verify_exact_original_pcm_and_private_archive_head',function()use($dir,$expected,$lessonId,$store,$version){if(!hash_equals($expected,hash_file('sha256',$dir.'/input.pcm')))throw new RuntimeException('probe_source_hash');$record=DB::table('recordings')->where('lesson_id',$lessonId)->first();if(!$record||$record->status!=='playable'||$record->storage_version!==$version||(int)$record->duration_samples!==161600)throw new RuntimeException('probe_recording_not_playable');$head=$store->head($record->object_key);if($head['bytes']!==(int)$record->bytes||!hash_equals($record->sha256,$head['sha256']??''))throw new RuntimeException('probe_archive_hash');});
 $report['status']='passed';
}catch(Throwable $error){$failed=true;$report['status']='failed';$report['failure']=['step'=>$step,'exception_type'=>get_class($error)];if($error instanceof Aws\Exception\AwsException)$report['failure']+=['http_status'=>$error->getStatusCode(),'provider_code'=>$error->getAwsErrorCode()];else $report['failure']['code']=preg_match('/^[a-z0-9_]+$/D',$error->getMessage())?$error->getMessage():'recovery_probe_failed';}
finally{
 $clean=true;if($store){foreach(array_unique($keys)as$key)try{$store->delete($key);}catch(Throwable){$clean=false;}}
 $report['steps'][]=['step'=>'cleanup_only_three_explicit_random_test_keys','status'=>$clean?'passed':'failed'];if(!$clean){$failed=true;$report['status']='failed';}
 foreach(array_unique($files)as$file)if(is_file($file))try{unlink(RecordingPaths::existing($file));}catch(Throwable){}
 DB::rollBack();$report['database_fixture']='rolled_back_without_deleting_existing_rows';$report['finished_at']=gmdate(DATE_ATOM);
}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit($failed?1:0);
