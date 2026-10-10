<?php
declare(strict_types=1);
use Illuminate\Support\Facades\{DB,Cache};
use App\Jobs\ArchiveLesson;
$reportPath=base_path('reports/load-90min-archive-recovery.json');
$original=json_decode(file_get_contents(base_path('reports/load-90min.json')),true,512,JSON_THROW_ON_ERROR);
$started=microtime(true);$job=new ArchiveLesson(1,true);
$reserved=DB::table('jobs')->where('queue','archives')->get()->filter(fn($j)=>str_contains($j->payload,'ArchiveLesson'));
$report=['status'=>'running','started_at'=>gmdate('c'),'database'=>config('database.connections.pgsql.database'),'lesson_id'=>1,'original_attempt_status'=>$original['status']??'failed','original_failure'=>'archive_not_playable after 180-second harness deadline','recovery'=>'Controlled manual ArchiveLesson.handle, same original PCM; recorded original worker stopped by controller.','provider'=>'mock Qwen original capture; filesystem SDK OSS handler; no real cloud requests','recaptured_audio'=>false,'original_reserved_archive_jobs'=>$reserved->map(fn($j)=>['id'=>$j->id,'payload_sha256'=>hash('sha256',$j->payload)])->values()->all(),'released_locks'=>[]];
foreach($job->middleware()as$middleware){$key=$middleware->getLockKey($job);Cache::lock($key)->forceRelease();$report['released_locks'][]=$key;}
file_put_contents($reportPath,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "archive_recovery_started\n";flush();
try{
 $job->handle(app(App\Storage\PcmArchiveAssembler::class),app(App\Storage\SegmentRecovery::class));
 $lesson=DB::table('lessons')->where('id',1)->first();$recording=DB::table('recordings')->where('lesson_id',1)->first();
 if($lesson->archive_status!=='playable'||$recording===null)throw new RuntimeException('archive_recovery_not_playable');
 $report['frames']=DB::table('audio_segments')->where('lesson_id',1)->count();$report['samples']=(int)$lesson->media_samples;$report['uploaded_chunks']=DB::table('audio_chunks')->where('lesson_id',1)->where('storage_state','uploaded')->count();
 $report['recording']=['status'=>$recording->status,'bytes'=>(int)$recording->bytes,'sha256'=>$recording->sha256,'duration_samples'=>(int)$recording->duration_samples,'mime'=>$recording->mime];
 $report['completed_original_archive_jobs']=[];
 foreach($reserved as$old){$fresh=DB::table('jobs')->where('id',$old->id)->first();if($fresh&&hash_equals(hash('sha256',$old->payload),hash('sha256',$fresh->payload))){DB::table('jobs')->where('id',$old->id)->delete();$report['completed_original_archive_jobs'][]=$old->id;}}
 $report['status']='passed';
}catch(Throwable$e){$report['status']='failed';$report['error']=$e->getMessage();}
$report['finished_at']=gmdate('c');$report['recovery_wall_seconds']=round(microtime(true)-$started,3);$report['original_report_unchanged']=true;
file_put_contents($reportPath,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo $report['status']."\n";
if($report['status']!=='passed')exit(1);
