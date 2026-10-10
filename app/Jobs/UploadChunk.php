<?php
declare(strict_types=1);
namespace App\Jobs;
use App\Storage\{StorageRegistry,RecordingPaths,ChunkPlanner,SegmentRecovery};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\{DB,Log};
use RuntimeException;
final class UploadChunk implements ShouldQueue {
 use Queueable;
 public int $tries=0;public int $maxExceptions=6;public int $timeout=120;public ?int $retryDeadline=null;
 public function __construct(public int $chunkId){$this->retryDeadline=time()+86400;$this->onQueue('recordings');}
 public function retryUntil():\DateTimeImmutable{return(new \DateTimeImmutable)->setTimestamp($this->retryDeadline??time()+86400);}
 public function middleware():array{$id=DB::table('audio_chunks')->where('id',$this->chunkId)->value('lesson_id');return[(new WithoutOverlapping('lesson-data:'.$id))->shared()->releaseAfter(10)->expireAfter(7500),(new WithoutOverlapping('chunk:'.$this->chunkId))->releaseAfter(10)->expireAfter(150)];}
 public function backoff():array{return[5,15,45,120,300];}
 public function handle(SegmentRecovery $recovery):void {
  $c=DB::table('audio_chunks')->find($this->chunkId);if(!$c||in_array($c->storage_state,['uploaded','failed'],true))return;
  $lesson=DB::table('lessons')->find($c->lesson_id);if(!$lesson||$lesson->purge_status!==null)return;
  DB::transaction(function()use($c){DB::table('lessons')->where('id',$c->lesson_id)->lockForUpdate()->first();ChunkPlanner::seal($this->chunkId);DB::table('audio_chunks')->where('id',$this->chunkId)->update(['storage_state'=>'uploading','attempts'=>DB::raw('attempts+1'),'updated_at'=>now()]);});$c=DB::table('audio_chunks')->find($this->chunkId);
  $temp=null;$out=null;
  try{$storage=StorageRegistry::resolve($c->storage_version);$path=RecordingPaths::chunk($c);RecordingPaths::capacity(dirname($path),(int)$c->sample_count*2);$temp=$path.'.tmp-'.bin2hex(random_bytes(8));$out=fopen($temp,'xb');if(!$out)throw new RuntimeException('recording_output_failed');$offset=0;$sequence=(int)$c->first_sequence;$frames=0;
   foreach(DB::table('audio_segments')->where('chunk_id',$c->id)->orderBy('sequence')->get()as$s){if((int)$s->sequence!==$sequence++||(int)$s->offset_bytes!==$offset||(int)$s->start_sample!==(int)$c->start_sample+intdiv($offset,2)||$s->storage_version!==$c->storage_version)throw new RuntimeException('recording_chunk_timeline_conflict');$in=fopen($recovery->ensure($s),'rb');if(!$in)throw new RuntimeException('recording_input_missing');try{if(stream_copy_to_stream($in,$out)!==(int)$s->sample_count*2)throw new RuntimeException('recording_output_write_failed');}finally{fclose($in);}$offset+=(int)$s->sample_count*2;$frames++;}
   if($frames!==(int)$c->frame_count||$offset!==(int)$c->sample_count*2||!fflush($out)||!fsync($out))throw new RuntimeException('recording_chunk_length_invalid');fclose($out);$out=null;$hash=hash_file('sha256',$temp);if(is_link($path))throw new RuntimeException('recording_path_not_allowed');if(is_file($path)&&!rename($path,$path.'.previous-'.bin2hex(random_bytes(6))))throw new RuntimeException('recording_output_publish_failed');if(!rename($temp,$path))throw new RuntimeException('recording_output_publish_failed');$key=$c->object_key?:$storage->configuration->objectKey('lessons/'.$c->lesson_id.'/chunks/'.$c->epoch.'/'.$c->group_sequence.'.pcm');
   DB::table('audio_chunks')->where('id',$c->id)->update(['local_path'=>$path,'sha256'=>$hash,'object_key'=>$key,'updated_at'=>now()]);$storage->upload($key,$path,'application/octet-stream',$hash);
   DB::transaction(function()use($c,$key){DB::table('audio_chunks')->where('id',$c->id)->update(['storage_state'=>'uploaded','updated_at'=>now()]);DB::table('audio_segments')->where('chunk_id',$c->id)->update(['object_key'=>$key,'storage_state'=>'uploaded','updated_at'=>now()]);});
  }catch(\Throwable $e){DB::table('audio_chunks')->where('id',$this->chunkId)->where('storage_state','<>','uploaded')->update(['storage_state'=>'pending','updated_at'=>now()]);Log::warning('recording.chunk_upload_failed',['chunk_id'=>$this->chunkId,'error_type'=>get_class($e)]);throw new RuntimeException($e->getMessage()==='storage_configuration_version_unavailable'?'storage_configuration_version_unavailable':'recording_chunk_upload_failed');}finally{if(is_resource($out))fclose($out);if($temp&&is_file($temp))unlink($temp);}
 }
 public function failed(?\Throwable $e):void{DB::table('audio_chunks')->where('id',$this->chunkId)->where('storage_state','<>','uploaded')->update(['storage_state'=>'failed','updated_at'=>now()]);}
}
