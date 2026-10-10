<?php
namespace App\Domain;
use Illuminate\Support\Facades\DB;
use App\Storage\{StorageRegistry,ChunkPlanner};
final class AudioStore {
 public function accept(int $lessonId,int $userId,string $connection,int $epoch,int $sequence,string $pcm,?array $limits=null): array {
  $samples=intdiv(strlen($pcm),2); if ($samples<1||$samples>1600||strlen($pcm)%2) throw new Failure('invalid_audio_frame');
  $limits??=\App\Settings\Settings::policies();return DB::transaction(function()use($lessonId,$userId,$connection,$epoch,$sequence,$pcm,$samples,$limits){
   $l=DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();
   Access::member(Access::user($userId));
   if (!$l || $l->status!=='live' || (int)$l->recorder_user_id!==$userId || $l->recorder_connection!==$connection || (int)$l->recorder_epoch!==$epoch || strtotime($l->lease_until)<time()) throw new Failure('recorder_stale',403);
   $hash=hash('sha256',$pcm);
   $existing=DB::table('audio_segments')->where(['lesson_id'=>$lessonId,'epoch'=>$epoch,'sequence'=>$sequence])->first();
   if ($existing) { if (!hash_equals($existing->sha256,$hash)) throw new Failure('audio_sequence_conflict',409);try{$saved=\App\Storage\RecordingPaths::existing($existing->local_path);if(filesize($saved)!==$samples*2||!hash_equals($hash,hash_file('sha256',$saved)))throw new \RuntimeException();}catch(\Throwable){throw new Failure('storage_unavailable',503);}return ['duplicate'=>true,'sequence'=>$sequence,'media_samples'=>(int)$l->media_samples]; }
   if ((int)$l->next_sequence!==$sequence) throw new Failure('audio_sequence_gap',409,['expected'=>(int)$l->next_sequence]);
   if(!$l->storage_version){if(app()->environment('testing')){$l->storage_version=StorageRegistry::register(config('classroom.oss'));DB::table('lessons')->where('id',$lessonId)->update(['storage_version'=>$l->storage_version]);}else throw new Failure('storage_configuration_version_unavailable',503);}
   \App\Reliability\Usage::acceptSamples($l,$samples,$limits);
   $root=storage_path("app/recordings/$lessonId/$epoch"); if (!is_dir($root)&&!mkdir($root,0700,true)) throw new Failure('storage_unavailable',503);
   if (disk_free_space($root)<$limits['min_disk_bytes']) throw new Failure('disk_capacity_limit',503);
   $path="$root/$sequence.pcm"; $temp="$path.tmp"; $fp=fopen($temp,'wb');
   if (!$fp) throw new Failure('storage_unavailable',503);
   try { if(fwrite($fp,$pcm)!==strlen($pcm)||!fflush($fp)||!fsync($fp)) throw new Failure('storage_unavailable',503); } finally { fclose($fp); }
   if (!rename($temp,$path)) throw new Failure('storage_unavailable',503);
   $segmentId=DB::table('audio_segments')->insertGetId(['lesson_id'=>$lessonId,'recorder_user_id'=>$userId,'epoch'=>$epoch,'sequence'=>$sequence,'start_sample'=>$l->media_samples,'sample_count'=>$samples,'sha256'=>$hash,'local_path'=>$path,'storage_version'=>$l->storage_version]);
   ChunkPlanner::attachFrame($l,$segmentId,$epoch,$sequence,(int)$l->media_samples,$samples);
   DB::table('lessons')->where('id',$lessonId)->update(['media_samples'=>(int)$l->media_samples+$samples,'next_sequence'=>$sequence+1,'updated_at'=>now()]);
   return ['duplicate'=>false,'sequence'=>$sequence,'segment_id'=>$segmentId,'media_samples'=>(int)$l->media_samples+$samples];
  });
 }
 public static function flushEpoch(int $lessonId,int $epoch):void{ChunkPlanner::flushEpoch($lessonId,$epoch);}
 public static function flushLesson(int $lessonId):void{ChunkPlanner::flushLesson($lessonId);}
}
