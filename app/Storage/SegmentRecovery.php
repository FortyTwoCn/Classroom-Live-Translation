<?php
declare(strict_types=1);
namespace App\Storage;
use Illuminate\Support\Facades\{Cache,DB};
use RuntimeException;
final class SegmentRecovery {
 public function __construct(){config(['classroom.min_disk_bytes'=>(int)\App\Settings\Settings::policies()['min_disk_bytes']]);}
 private array $verifiedChunks=[];
 private static function valid(string $path,int $bytes,string $hash):bool {try{$p=RecordingPaths::existing($path);return filesize($p)===$bytes&&hash_equals($hash,hash_file('sha256',$p));}catch(\Throwable){return false;}}
 private static function publish(string $temp,string $target):void {
  if(is_link($target))throw new RuntimeException('recording_path_not_allowed');
  if(is_file($target)){RecordingPaths::existing($target);if(!rename($target,$target.'.corrupt-'.bin2hex(random_bytes(6))))throw new RuntimeException('recording_quarantine_failed');}
  if(!rename($temp,$target))throw new RuntimeException('recording_output_publish_failed');
 }
 public function ensure(object $segment):string {
  $bytes=(int)$segment->sample_count*2;$hash=(string)$segment->sha256;
  if(self::valid((string)$segment->local_path,$bytes,$hash))return RecordingPaths::existing($segment->local_path);
  return Cache::lock('segment-recovery:'.$segment->id,150)->block(15,function()use($segment,$bytes,$hash){
   $target=RecordingPaths::segment($segment);if(self::valid($target,$bytes,$hash)){DB::table('audio_segments')->where('id',$segment->id)->update(['local_path'=>$target]);return $target;}
   RecordingPaths::capacity(dirname($target),$bytes);$temp=$target.'.tmp-'.bin2hex(random_bytes(8));
   try{if($segment->chunk_id??null){$chunk=DB::table('audio_chunks')->find($segment->chunk_id);if(!$chunk||$chunk->storage_version!==$segment->storage_version||(int)$chunk->lesson_id!==(int)$segment->lesson_id||(int)$chunk->epoch!==(int)$segment->epoch||$segment->offset_bytes===null||(int)$segment->offset_bytes!==((int)$segment->start_sample-(int)$chunk->start_sample)*2||(int)$segment->offset_bytes<0||(int)$segment->offset_bytes+$bytes>(int)$chunk->sample_count*2)throw new RuntimeException('recording_chunk_reference_invalid');$source=$this->chunk($chunk);$in=fopen($source,'rb');$out=fopen($temp,'xb');if(!$in||!$out){if(is_resource($in))fclose($in);if(is_resource($out))fclose($out);throw new RuntimeException('recording_output_failed');}try{if(fseek($in,(int)$segment->offset_bytes)!==0||stream_copy_to_stream($in,$out,$bytes)!==$bytes||!fflush($out)||!fsync($out))throw new RuntimeException('recording_output_write_failed');}finally{fclose($in);fclose($out);}if(filesize($temp)!==$bytes||!hash_equals($hash,hash_file('sha256',$temp))){unset($this->verifiedChunks[$chunk->id]);throw new RuntimeException('recording_segment_checksum_failed');}}
    else{$storage=StorageRegistry::resolve($segment->storage_version);$key=$segment->object_key?:$storage->configuration->objectKey('lessons/'.$segment->lesson_id.'/segments/'.$segment->epoch.'/'.$segment->sequence.'.pcm');$storage->downloadVerified($key,$temp,$bytes,$hash);}
    self::publish($temp,$target);DB::table('audio_segments')->where('id',$segment->id)->update(['local_path'=>$target,'updated_at'=>now()]);return $target;
   }finally{if(is_file($temp))unlink($temp);}
  });
 }
 public function chunk(object $chunk):string {
  $bytes=(int)$chunk->sample_count*2;$hash=(string)$chunk->sha256;$target=RecordingPaths::chunk($chunk);
  $memo=$this->verifiedChunks[$chunk->id]??null;
  if($memo&&$memo['hash']===$hash){try{clearstatcache(true,$target);RecordingPaths::existing($target);if(!is_link($target)&&filesize($target)===$bytes&&filemtime($target)===$memo['mtime']&&filectime($target)===$memo['ctime'])return $target;}catch(\Throwable){}unset($this->verifiedChunks[$chunk->id]);}
  return Cache::lock('chunk-recovery:'.$chunk->id,150)->block(15,function()use($chunk,$bytes,$hash,$target){
   if(!self::valid($target,$bytes,$hash)){if(!$chunk->sha256||!$chunk->object_key)throw new RuntimeException('recording_chunk_not_backed_up');RecordingPaths::capacity(dirname($target),$bytes);$temp=$target.'.tmp-'.bin2hex(random_bytes(8));try{StorageRegistry::resolve($chunk->storage_version)->downloadVerified($chunk->object_key,$temp,$bytes,$hash);self::publish($temp,$target);}finally{if(is_file($temp))unlink($temp);}}
   DB::table('audio_chunks')->where('id',$chunk->id)->update(['local_path'=>$target]);clearstatcache(true,$target);$this->verifiedChunks[$chunk->id]=['hash'=>$hash,'mtime'=>filemtime($target),'ctime'=>filectime($target)];return $target;
  });
 }
}
