<?php
declare(strict_types=1);
namespace App\Storage;
use App\Jobs\UploadChunk;
use Illuminate\Support\Facades\DB;
use RuntimeException;
final class ChunkPlanner {
 public static function attachFrame(object $lesson,int $segmentId,int $epoch,int $sequence,int $startSample,int $sampleCount):void {
  $c=DB::table('audio_chunks')->where('lesson_id',$lesson->id)->where('epoch',$epoch)->where('storage_state','open')->lockForUpdate()->first();$created=false;
  if(!$c){$group=(int)DB::table('audio_chunks')->where('lesson_id',$lesson->id)->where('epoch',$epoch)->max('group_sequence');$has=DB::table('audio_chunks')->where('lesson_id',$lesson->id)->where('epoch',$epoch)->exists();$id=DB::table('audio_chunks')->insertGetId(['lesson_id'=>$lesson->id,'epoch'=>$epoch,'group_sequence'=>$has?$group+1:0,'first_sequence'=>$sequence,'last_sequence'=>$sequence,'frame_count'=>1,'start_sample'=>$startSample,'sample_count'=>$sampleCount,'storage_version'=>$lesson->storage_version]);$c=DB::table('audio_chunks')->find($id);$offset=0;$created=true;}
  else{if((int)$c->last_sequence+1!==$sequence||(int)$c->start_sample+(int)$c->sample_count!==$startSample||$c->storage_version!==$lesson->storage_version)throw new RuntimeException('recording_chunk_timeline_conflict');$offset=(int)$c->sample_count*2;DB::table('audio_chunks')->where('id',$c->id)->update(['last_sequence'=>$sequence,'frame_count'=>$c->frame_count+1,'sample_count'=>$c->sample_count+$sampleCount,'updated_at'=>now()]);$c->frame_count++;}
  DB::table('audio_segments')->where('id',$segmentId)->update(['chunk_id'=>$c->id,'offset_bytes'=>$offset]);
  if($created)UploadChunk::dispatch((int)$c->id)->delay(now()->addSeconds(10));
  if((int)$c->frame_count===100){self::seal((int)$c->id);UploadChunk::dispatch((int)$c->id);}
 }
 public static function seal(int $id):void{DB::table('audio_chunks')->where('id',$id)->where('storage_state','open')->update(['storage_state'=>'pending','sealed_at'=>now(),'updated_at'=>now()]);}
 public static function flushEpoch(int $lessonId,int $epoch):void{self::flush($lessonId,$epoch);}
 public static function flushLesson(int $lessonId):void{self::flush($lessonId,null);}
 private static function flush(int $lessonId,?int $epoch):void{DB::transaction(function()use($lessonId,$epoch){DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();$q=DB::table('audio_chunks')->where('lesson_id',$lessonId)->where('storage_state','open');if($epoch!==null)$q->where('epoch',$epoch);foreach($q->lockForUpdate()->get()as$c){self::seal((int)$c->id);UploadChunk::dispatch((int)$c->id);}});}
}
