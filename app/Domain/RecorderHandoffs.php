<?php
namespace App\Domain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable invitations; connection identity is never a username alone. */
final class RecorderHandoffs {
 public function create(int $lessonId,object $actor,string $actorConnection,object $target,string $targetConnection,string $kind,string $id): object {
  Access::member($actor); Access::member($target);
  if(!Str::isUuid($id)||!in_array($kind,['invite','request'],true))throw new Failure('validation_failed',422);
  return DB::transaction(function()use($lessonId,$actor,$actorConnection,$target,$targetConnection,$kind,$id){
   $l=DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();
   if(!$l||$l->status==='ended'||$l->deleted_at)throw new Failure('invalid_state',409);
   $this->stableModel($l);
   $existing=DB::table('recorder_handoffs')->find($id);
   if($existing){if((int)$existing->requester_id!==(int)$actor->id||(int)$existing->lesson_id!==$lessonId)throw new Failure('idempotency_conflict',409);return $existing;}
   $source=$kind==='invite'?$actor:$target; $destination=$kind==='invite'?$target:$actor;
   $sourceConnection=$kind==='invite'?$actorConnection:$targetConnection;
   $destinationConnection=$kind==='invite'?$targetConnection:$actorConnection;
   if((int)$l->recorder_user_id!==(int)$source->id)throw new Failure('not_recorder',403);
   if($l->recorder_connection&&$l->recorder_connection!==$sourceConnection)throw new Failure('recorder_stale',403);
   if((int)$source->id===(int)$destination->id&&$sourceConnection===$destinationConnection)throw new Failure('handoff_same_device',422);
   if(DB::table('recorder_handoffs')->where('lesson_id',$lessonId)->whereIn('status',['pending','preparing'])->count()>=10)throw new Failure('handoff_limit',429);
   DB::table('recorder_handoffs')->insert(['id'=>$id,'lesson_id'=>$lessonId,'kind'=>$kind,'requester_id'=>$actor->id,'source_user_id'=>$source->id,'target_user_id'=>$destination->id,'source_connection'=>$sourceConnection,'target_connection'=>$destinationConnection,'epoch'=>$l->recorder_epoch,'expires_at'=>now()->addSeconds(config('classroom.invite_seconds',60))]);
   $this->event($lessonId,$id,'pending'); return DB::table('recorder_handoffs')->find($id);
  });
 }
 public function respond(int $lessonId,object $actor,string $connection,string $id,string $action): object {
  return DB::transaction(function()use($lessonId,$actor,$connection,$id,$action){
   $l=DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();$h=$this->current($l,$id);
   if($action==='cancel'){if((int)$h->requester_id!==(int)$actor->id)throw new Failure('forbidden',403);$status='cancelled';}
   else{$recipient=$h->kind==='invite'?$h->target_connection:$h->source_connection;if($recipient!==$connection)throw new Failure('forbidden',403);$status=$action==='accept'?'preparing':($action==='reject'?'rejected':null);if(!$status)throw new Failure('validation_failed',422);if($action==='accept')$this->stableModel($l);}
   if($h->status!=='pending')throw new Failure('handoff_stale',409);
   DB::table('recorder_handoffs')->where('id',$id)->update(['status'=>$status,'updated_at'=>now()]);$this->event($lessonId,$id,$status);$h->status=$status;return $h;
  });
 }
 public function prepared(int $lessonId,object $actor,string $connection,string $id,bool $ready): object {
  return DB::transaction(function()use($lessonId,$actor,$connection,$id,$ready){
   $l=DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();$h=$this->current($l,$id);
   if($h->status!=='preparing'||$h->target_connection!==$connection||(int)$h->target_user_id!==(int)$actor->id)throw new Failure('handoff_stale',409);
   if(!$ready){DB::table('recorder_handoffs')->where('id',$id)->update(['status'=>'failed','updated_at'=>now()]);$this->event($lessonId,$id,'failed');return $l;}
   $this->stableModel($l);
   // Atomic invalidation prevents late packets from the previous device.
   DB::table('lessons')->where('id',$lessonId)->update(['recorder_user_id'=>$actor->id,'recorder_connection'=>null,'lease_until'=>null,'recorder_offline_at'=>null,'recorder_epoch'=>$l->recorder_epoch+1,'status'=>$l->status==='live'?'paused':$l->status,'translation_status'=>$l->status==='live'?'finishing':$l->translation_status]);
   DB::table('recorder_handoffs')->where('id',$id)->update(['status'=>'completed','updated_at'=>now()]);
   $this->invalidate($lessonId);$this->event($lessonId,$id,'completed');
   DB::table('audit')->insert(['lesson_id'=>$lessonId,'user_id'=>$actor->id,'action'=>'recorder_transferred','details'=>json_encode(['from_user'=>$h->source_user_id,'to_user'=>$actor->id,'handoff_id'=>$id,'old_epoch'=>$l->recorder_epoch])]);Access::lessonEvent($lessonId);return $l;
  });
 }
 public function takeover(int $lessonId,object $actor,bool $force,bool $holderOnline): object {
  Access::member($actor);if($force)Access::admin($actor);
  return DB::transaction(function()use($lessonId,$actor,$force,$holderOnline){
   $l=DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();if(!$l||$l->status==='ended'||$l->deleted_at)throw new Failure('invalid_state',409);
   $this->stableModel($l);
   if(!$force){if($holderOnline||$l->recorder_connection)throw new Failure('recorder_busy',409);$offline=$l->recorder_offline_at??$l->created_at;if(strtotime($offline)>time()-config('classroom.takeover_seconds',30))throw new Failure('recorder_recovery_window',409);}
   DB::table('lessons')->where('id',$lessonId)->update(['recorder_user_id'=>$actor->id,'recorder_connection'=>null,'lease_until'=>null,'recorder_epoch'=>$l->recorder_epoch+1,'recorder_offline_at'=>null,'status'=>$l->status==='live'?'paused':$l->status]);$this->invalidate($lessonId);
   DB::table('audit')->insert(['lesson_id'=>$lessonId,'user_id'=>$actor->id,'action'=>$force?'recorder_admin_takeover':'recorder_offline_takeover','details'=>json_encode(['old_epoch'=>$l->recorder_epoch,'from_user'=>$l->recorder_user_id])]);Access::lessonEvent($lessonId);return $l;
  });
 }
 public function invalidate(int $lessonId): void {DB::table('recorder_handoffs')->where('lesson_id',$lessonId)->whereIn('status',['pending','preparing'])->update(['status'=>'cancelled','updated_at'=>now()]);}
 public function cancelConnection(string $key): void {foreach(DB::table('recorder_handoffs')->whereIn('status',['pending','preparing'])->where(function($q)use($key){$q->where('source_connection',$key)->orWhere('target_connection',$key);})->get() as $h)DB::transaction(function()use($h){DB::table('lessons')->where('id',$h->lesson_id)->lockForUpdate()->first();if(DB::table('recorder_handoffs')->where('id',$h->id)->whereIn('status',['pending','preparing'])->update(['status'=>'cancelled','updated_at'=>now()]))$this->event((int)$h->lesson_id,$h->id,'cancelled');});}
 public function expire(): void {foreach(DB::table('recorder_handoffs')->whereIn('status',['pending','preparing'])->where('expires_at','<',now())->get() as $h)DB::transaction(function()use($h){DB::table('lessons')->where('id',$h->lesson_id)->lockForUpdate()->first();if(DB::table('recorder_handoffs')->where('id',$h->id)->whereIn('status',['pending','preparing'])->update(['status'=>'expired','updated_at'=>now()]))$this->event((int)$h->lesson_id,$h->id,'expired');});}
 public function items(int $lessonId,int $userId,string $connection): array {return DB::table('recorder_handoffs as h')->join('users as a','a.id','=','h.requester_id')->join('users as b','b.id','=','h.target_user_id')->where('h.lesson_id',$lessonId)->where(function($q)use($connection){$q->where('h.source_connection',$connection)->orWhere('h.target_connection',$connection);})->where('h.updated_at','>',now()->subMinutes(3))->select('h.*','a.name as requester_name','b.name as target_name')->orderByDesc('h.created_at')->limit(20)->get()->map(function($h)use($userId,$connection){$recipient=$h->kind==='invite'?$h->target_connection:$h->source_connection;return ['id'=>$h->id,'kind'=>$h->kind,'status'=>$h->status,'role'=>$recipient===$connection?'recipient':((int)$h->requester_id===$userId?'sender':'target'),'requester_name'=>$h->requester_name,'target_name'=>$h->target_name,'expires_at'=>$h->expires_at,'needs_prepare'=>$h->status==='preparing'&&$h->target_connection===$connection];})->all();}
 private function current(?object $l,string $id): object {if(!$l||$l->status==='ended'||$l->deleted_at)throw new Failure('handoff_stale',409);$h=DB::table('recorder_handoffs')->where('id',$id)->where('lesson_id',$l->id)->lockForUpdate()->first();if(!$h||strtotime($h->expires_at)<time()||(int)$h->epoch!==(int)$l->recorder_epoch||(int)$h->source_user_id!==(int)$l->recorder_user_id)throw new Failure('handoff_stale',409);return $h;}
 private function event(int $id,string $handoff,string $status): void {Access::event($id,'handoff.updated',['id'=>$handoff,'status'=>$status]);}
 private function stableModel(object $l):void {if(in_array($l->translation_status,['connecting','configuring','finishing'],true))throw new Failure('model_session_busy',409);}
}
