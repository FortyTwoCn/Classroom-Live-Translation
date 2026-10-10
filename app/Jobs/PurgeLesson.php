<?php
namespace App\Jobs;
use App\Storage\StorageRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\{DB,File};
final class PurgeLesson implements ShouldQueue {
 use Queueable;public int $tries=0;public int $maxExceptions=4;public int $timeout=1800;public ?int $retryDeadline=null;
 public function __construct(public int $lessonId){$this->retryDeadline=time()+86400;$this->onQueue('maintenance');}
 public function retryUntil():\DateTimeImmutable{return(new \DateTimeImmutable)->setTimestamp($this->retryDeadline??time()+86400);}
 public function middleware():array {return [(new WithoutOverlapping('lesson-data:'.$this->lessonId))->shared()->releaseAfter(15)->expireAfter(7500)];}
 public function backoff():array {return [30,120,600];}
 public function handle():void {
  $id=$this->lessonId;$l=DB::table('lessons')->find($id);if(!$l||$l->purge_status==='purged')return;if(!$l->deleted_at||$l->status!=='ended'||!in_array($l->purge_status,['queued','processing','failed']))throw new \RuntimeException('purge_state_invalid');
  DB::table('lessons')->where('id',$id)->update(['purge_status'=>'processing']);
  try{
   // Every cloud key stays in the durable deletion manifest until DELETE succeeds.
   foreach(['audio_segments','audio_chunks','recordings']as$table){foreach(DB::table($table)->where('lesson_id',$id)->whereNotNull('object_key')->select('storage_version','object_key')->distinct()->cursor()as$o)DB::table('purge_objects')->insertOrIgnore(['lesson_id'=>$id,'storage_version'=>$o->storage_version,'object_key'=>$o->object_key]);}
   // Archive PUT can finish before its playable row is published. The fixed
   // canonical archive key is owned by this lesson's registered history.
   // Empty legacy lessons have no destination: never guess from current env.
   if($l->storage_version!==null||((int)$l->media_samples>0&&DB::table('audio_segments')->where('lesson_id',$id)->exists())){
    $version=StorageRegistry::lessonVersion($l);$cfg=StorageRegistry::configuration($version);
    DB::table('purge_objects')->insertOrIgnore(['lesson_id'=>$id,'storage_version'=>$version,'object_key'=>$cfg->objectKey('lessons/'.$id.'/archive/original.m4a')]);
   }
   // Old pre-fix per-frame PUTs can likewise lack a persisted key. Derive
   // only the canonical key of that exact legacy frame's registered version.
   foreach(DB::table('audio_segments')->where('lesson_id',$id)->whereNull('chunk_id')->whereNull('object_key')->cursor()as$s){
    $cfg=StorageRegistry::configuration($s->storage_version);
    DB::table('purge_objects')->insertOrIgnore(['lesson_id'=>$id,'storage_version'=>$s->storage_version,'object_key'=>$cfg->objectKey('lessons/'.$id.'/segments/'.$s->epoch.'/'.$s->sequence.'.pcm')]);
   }
   foreach(DB::table('purge_objects')->where('lesson_id',$id)->where('status','pending')->cursor()as$o){StorageRegistry::resolve($o->storage_version)->delete($o->object_key);DB::table('purge_objects')->where('id',$o->id)->update(['status'=>'deleted','updated_at'=>now()]);}
   // Canonical directory only; database local_path cannot nominate arbitrary files.
   $root=realpath(storage_path('app/recordings'));$target=realpath(storage_path('app/recordings/'.$id));
   if($target!==false){$normalize=fn($s)=>PHP_OS_FAMILY==='Windows'?strtolower(str_replace('\\','/',$s)):str_replace('\\','/',$s);if(!$root||!str_starts_with($normalize($target),$normalize($root).'/')||basename($target)!==(string)$id||is_link(storage_path('app/recordings/'.$id)))throw new \RuntimeException('purge_path_invalid');if(!File::deleteDirectory($target))throw new \RuntimeException('purge_local_cleanup_failed');}
   foreach(DB::table('subtitle_exports')->where('lesson_id',$id)->get()as$export){if(!\Illuminate\Support\Str::isUuid($export->id)||!in_array($export->format,['txt','md','srt','vtt'],true))throw new \RuntimeException('purge_export_path_invalid');$directory=storage_path('app/private/subtitle-exports/'.$export->id);for($parent=$directory;strlen($parent)>=strlen(storage_path());$parent=dirname($parent)){if(is_link($parent))throw new \RuntimeException('purge_export_path_invalid');if($parent===storage_path())break;}foreach([$directory.'/subtitles.'.$export->format,$directory.'/subtitles.'.$export->format.'.tmp']as$file)if(is_file($file)&&!unlink($file))throw new \RuntimeException('purge_export_cleanup_failed');}
   DB::transaction(function()use($id){DB::table('lessons')->where('id',$id)->lockForUpdate()->first();DB::table('lesson_glossary_snapshots')->where('lesson_id',$id)->delete();DB::table('subtitle_export_rows')->whereIn('export_id',DB::table('subtitle_exports')->where('lesson_id',$id)->select('id'))->delete();DB::table('subtitle_exports')->where('lesson_id',$id)->update(['status'=>'expired','file_path'=>null,'metadata'=>'{}','report'=>'{}']);DB::table('subtitle_revisions')->where('lesson_id',$id)->update(['restored_revision_id'=>null]);DB::table('subtitle_revisions')->where('lesson_id',$id)->delete();DB::table('subtitle_revision_batches')->where('lesson_id',$id)->delete();DB::table('subtitle_units')->where('lesson_id',$id)->delete();DB::table('audio_segments')->where('lesson_id',$id)->delete();DB::table('audio_chunks')->where('lesson_id',$id)->delete();DB::table('recordings')->where('lesson_id',$id)->delete();DB::table('lesson_events')->where('lesson_id',$id)->delete();DB::table('lessons')->where('id',$id)->update(['purge_status'=>'purged','archive_status'=>'failed','updated_at'=>now()]);DB::table('audit')->insert(['lesson_id'=>$id,'action'=>'lesson.purged','details'=>json_encode(['database_content'=>'removed','oss'=>'manifest_deleted','local'=>'removed','audit'=>'retained','backups'=>'separate_retention_not_erased'])]);});
  }catch(\Throwable $e){DB::table('lessons')->where('id',$id)->update(['purge_status'=>'failed']);logger()->warning('lesson.purge_failed',['lesson_id'=>$id,'error_type'=>get_class($e)]);throw new \RuntimeException('lesson_purge_failed');}
 }
 public function failed(?\Throwable $e):void {DB::table('lessons')->where('id',$this->lessonId)->where('purge_status','<>','purged')->update(['purge_status'=>'failed']);}
}
