<?php
namespace App\Reliability;
use App\Domain\{Access,Failure,AudioStore};
use Illuminate\Support\Facades\DB;
final class Recovery {
 public static function interrupted(object $lesson,string $reason,?string $sid=null,?int $endSample=null):void {
  DB::transaction(function()use($lesson,$reason,$sid,$endSample){$lesson=DB::table('lessons')->where('id',$lesson->id)->lockForUpdate()->first();if(!$lesson||$lesson->purge_status!==null)return;
  $start=$sid?(int)DB::table('model_sessions')->where('id',$sid)->value('base_sample'):(int)$lesson->media_samples;
  if(!DB::table('recording_issues')->where('lesson_id',$lesson->id)->where('epoch',$lesson->recorder_epoch)->where('kind',$reason)->where('start_sample',$start)->exists()){
   DB::table('recording_issues')->insert(['lesson_id'=>$lesson->id,'model_session_id'=>$sid,'epoch'=>$lesson->recorder_epoch,'start_sample'=>$start,'end_sample'=>$sid?($endSample??(int)$lesson->media_samples):null,'kind'=>$reason,'details'=>json_encode(['audio_saved_samples'=>(int)$lesson->media_samples,'model_results_exactly_once'=>false,'media_strategy'=>'skip_unreceived'])]);
   Access::event((int)$lesson->id,'recording.issue',['kind'=>$reason,'epoch'=>(int)$lesson->recorder_epoch,'start_sample'=>$start,'end_sample'=>$sid?($endSample??(int)$lesson->media_samples):null]);
  }
  });
 }
 public static function restart():void {
  DB::transaction(function(){
   foreach(DB::table('lessons')->where(function($q){$q->whereIn('translation_status',['connecting','configuring','ready','finishing'])->orWhere('status','live');})->lockForUpdate()->get()as$lesson){
    $sessions=DB::table('model_sessions')->where('lesson_id',$lesson->id)->whereNull('ended_at')->get();foreach($sessions as$s){self::interrupted($lesson,'translation_uncertain',$s->id);DB::table('model_sessions')->where('id',$s->id)->update(['status'=>'interrupted','ended_at'=>now()]);}
    if($lesson->purge_status!==null)continue;
    foreach(DB::table('subtitle_units')->where('lesson_id',$lesson->id)->whereIn('status',['streaming','text_done'])->get()as$unit){DB::table('subtitle_units')->where('id',$unit->id)->update(['status'=>'interrupted','updated_at'=>now()]);$unit->status='interrupted';Access::event((int)$lesson->id,'subtitle.updated',['unit'=>Access::unit($unit)]);}
    DB::table('lessons')->where('id',$lesson->id)->update(['status'=>$lesson->status==='ended'?'ended':'paused','translation_status'=>'interrupted','recorder_connection'=>null,'lease_until'=>null,'recorder_offline_at'=>now()]);DB::table('recorder_handoffs')->where('lesson_id',$lesson->id)->whereIn('status',['pending','preparing'])->update(['status'=>'cancelled','updated_at'=>now()]);AudioStore::flushLesson((int)$lesson->id);Access::lessonEvent((int)$lesson->id);
    if($lesson->status==='ended')\App\Jobs\ArchiveLesson::dispatch($lesson->id)->afterCommit();
   }
  });
 }
 public static function clientIssue(int $id,object $user,array $v,?string $sessionId=null):array {
  return DB::transaction(function()use($id,$user,$v,$sessionId){$l=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();$actor=\App\Content\MutationActor::lock($user);if(!$l||$l->deleted_at||$l->purge_status!==null)throw new Failure('lesson_unavailable',404);Access::lesson($id,$actor,$sessionId);
   // A previous epoch needs recorded participation; it never grants upload rights.
   $epoch=(int)$v['epoch'];$own=(int)$l->recorder_epoch===$epoch&&(int)$l->recorder_user_id===(int)$actor->id;
   if(!$own)$own=DB::table('audio_segments')->where(['lesson_id'=>$id,'epoch'=>$epoch,'recorder_user_id'=>$actor->id])->exists()||DB::table('model_sessions')->where(['lesson_id'=>$id,'recorder_user_id'=>$actor->id])->whereRaw("config->>'epoch' = ?",[(string)$epoch])->exists();
   if(!$own)throw new Failure('recorder_stale',403);
   $existing=DB::table('recording_issues')->where(['lesson_id'=>$id,'epoch'=>$epoch,'kind'=>'capture_uncertain'])->whereRaw("details->>'reason' = ?",[$v['reason']])->whereRaw("details->>'next_sequence' = ?",[(string)$v['next_sequence']])->first();
   if($existing)return ['issue'=>self::issue($existing)];
   $segments=DB::table('audio_segments')->where(['lesson_id'=>$id,'epoch'=>$epoch]);$next=(int)(clone$segments)->max('sequence');$next=(clone$segments)->exists()?$next+1:0;$anchor=(int)((clone$segments)->selectRaw('max(start_sample+sample_count) AS finish')->value('finish')??$l->media_samples);
   $details=['reason'=>$v['reason'],'last_ack_sequence'=>$v['last_ack_sequence'],'next_sequence'=>$v['next_sequence'],'persisted_next_sequence'=>$next,'estimated_lost_ms'=>$v['estimated_lost_ms']??null,'media_strategy'=>'skip_unreceived','unreceived_audio_recoverable'=>false];
   $iid=DB::table('recording_issues')->insertGetId(['lesson_id'=>$id,'epoch'=>$epoch,'start_sample'=>$anchor,'kind'=>'capture_uncertain','details'=>json_encode($details)]);Access::event($id,'recording.issue',['kind'=>'capture_uncertain','epoch'=>$epoch,'start_sample'=>$anchor]);return ['issue'=>self::issue(DB::table('recording_issues')->find($iid))];
  });
 }
 public static function issue(object $row):array {$a=(array)$row;$a['details']=json_decode($a['details']??'{}',true);unset($a['model_session_id'],$a['lesson_id']);return $a;}
 public static function replay(int $lessonId,int $after,int $last):array {
  if($after<1||$after>$last)return ['mode'=>'snapshot','from_seq'=>$after,'count'=>0,'truncated'=>$after>$last,'missed_events'=>[]];
  $q=DB::table('lesson_events')->where('lesson_id',$lessonId)->where('seq','>',$after)->where('seq','<=',$last)->where('event_type','not like','control.%');$count=(clone$q)->count();if($count>500)return ['mode'=>'snapshot','from_seq'=>$after,'count'=>$count,'truncated'=>true,'missed_events'=>[]];
  return ['mode'=>'replay','from_seq'=>$after,'count'=>$count,'truncated'=>false,'missed_events'=>$q->orderBy('seq')->get()->map(fn($e)=>['type'=>'event','seq'=>(int)$e->seq,'event_type'=>$e->event_type,'payload'=>json_decode($e->payload,true)])->all()];
 }
}
