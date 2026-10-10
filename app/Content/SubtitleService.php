<?php
declare(strict_types=1);
namespace App\Content;
use App\Domain\{Access,Failure};
use Illuminate\Support\Facades\DB;

final class SubtitleService {
 public function adminList(object $admin,array $filters):array {
  Access::admin($admin);$query=DB::table('subtitle_units as s')->join('lessons as l','l.id','=','s.lesson_id')->join('courses as c','c.id','=','l.course_id');
  if(!empty($filters['course_id']))$query->where('l.course_id',$filters['course_id']);if(!empty($filters['lesson_id']))$query->where('s.lesson_id',$filters['lesson_id']);
  if(($filters['kind']??'all')!=='all')$query->where('s.kind',$filters['kind']);
  if(($filters['edited']??'all')==='yes')$query->where('s.version','>',1);elseif(($filters['edited']??'all')==='no')$query->where('s.version',1);
  if(($filters['deleted']??'active')==='active')$query->whereNull('s.deleted_at');elseif(($filters['deleted']??'active')==='only')$query->whereNotNull('s.deleted_at');
  if(!empty($filters['search'])){$search='%'.addcslashes($filters['search'],'%_\\').'%';$query->where(fn($q)=>$q->whereRaw('s.original_text ILIKE ?',[$search])->orWhereRaw('COALESCE(s.current_text,s.original_text) ILIKE ?',[$search]));}
  $page=(int)($filters['page']??1);$total=(clone $query)->count();$units=$query->select('s.*','c.name as course_name','l.name as lesson_name')->orderByDesc('s.id')->offset(($page-1)*30)->limit(30)->get()->map(fn($u)=>Access::unit($u));
  return ['units'=>$units,'total'=>$total,'page'=>$page,'per_page'=>30];
 }
 public function revise(int $lessonId,?object $user,array $changes,string $key,?string $sessionId=null):array {
  Access::member($user);if(count($changes)<1||count($changes)>20)throw new Failure('invalid_revision');$hash=hash('sha256',json_encode($changes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
  return DB::transaction(function()use($lessonId,$user,$changes,$key,$hash,$sessionId){DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();$user=MutationActor::lock($user);$lesson=Access::lesson($lessonId,$user,$sessionId);if($lesson->editing_frozen)throw new Failure('subtitle_editing_frozen',423);
   $batch=DB::table('subtitle_revision_batches')->where('id',$key)->first();if($batch){if((int)$batch->actor_id!==(int)$user->id||$batch->request_hash!==$hash||(int)$batch->lesson_id!==$lessonId)throw new Failure('idempotency_conflict',409);return json_decode($batch->result,true,flags:JSON_THROW_ON_ERROR);}
   $ids=array_column($changes,'unit_id');if(count(array_unique($ids))!==count($ids))throw new Failure('invalid_revision');$units=DB::table('subtitle_units')->where('lesson_id',$lessonId)->whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');$conflicts=[];
   foreach($changes as $change){$unit=$units->get($change['unit_id']);if(!$unit||$unit->deleted_at)throw new Failure('subtitle_unavailable',404);if($unit->status!=='completed')throw new Failure('subtitle_not_completed',409);if((int)$unit->version!==(int)$change['expected_version'])$conflicts[]=Access::unit($unit);if(!is_string($change['text'])||trim($change['text'])===''||mb_strlen($change['text'])>20000)throw new Failure('subtitle_text_invalid');}
   if($conflicts)throw new Failure('subtitle_version_conflict',409,['latest_units'=>$conflicts]);
   DB::table('subtitle_revision_batches')->insert(['id'=>$key,'lesson_id'=>$lessonId,'actor_id'=>$user->id,'request_hash'=>$hash,'result'=>'{}']);$saved=[];
   foreach($changes as $change){$unit=$units->get($change['unit_id']);$before=$unit->current_text??$unit->original_text;$restored=$change['restored_revision_id']??null;
    if($before!==$change['text']||array_key_exists('restored_revision_id',$change)){$version=(int)$unit->version+1;DB::table('subtitle_revisions')->insert(['unit_id'=>$unit->id,'lesson_id'=>$lessonId,'batch_id'=>$key,'actor_id'=>$user->id,'version'=>$version,'before_text'=>$before,'after_text'=>$change['text'],'restored_revision_id'=>$restored]);DB::table('subtitle_units')->where('id',$unit->id)->update(['current_text'=>$change['text'],'version'=>$version,'updated_at'=>now()]);}
    $updated=Access::unit(DB::table('subtitle_units')->where('id',$unit->id)->first());$saved[]=$updated;Access::event($lessonId,'subtitle.updated',['unit'=>$updated]);
   }
   $result=['units'=>$saved];DB::table('subtitle_revision_batches')->where('id',$key)->update(['result'=>json_encode($result,JSON_UNESCAPED_UNICODE)]);return $result;
  });
 }
 public function history(int $lessonId,int $unitId,?object $user,?string $sessionId=null,?int $before=null):array {
  return DB::transaction(function()use($lessonId,$unitId,$user,$sessionId,$before){DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();
  Access::lesson($lessonId,$user,$sessionId);$unit=DB::table('subtitle_units')->where('lesson_id',$lessonId)->where('id',$unitId)->whereNull('deleted_at')->first();if(!$unit)throw new Failure('subtitle_unavailable',404);
  $q=DB::table('subtitle_revisions as r')->join('users as u','u.id','=','r.actor_id')->where('r.unit_id',$unitId);if($before)$q->where('r.id','<',$before);$rows=$q->select('r.id','r.version','r.before_text','r.after_text','r.restored_revision_id','r.created_at','u.name as actor_name')->orderByDesc('r.id')->limit(50)->get();
  $source=$unit->kind==='source'?$unit->item_id:($unit->mapping==='explicit_previous_item_id'?$unit->source_item_id:null);$related=[];
  if($source!==null)$related=DB::table('subtitle_units')->where('lesson_id',$lessonId)->where('model_session_id',$unit->model_session_id)->whereNull('deleted_at')->where(fn($q)=>$q->where(fn($q)=>$q->where('kind','source')->where('item_id',$source))->orWhere(fn($q)=>$q->where('kind','translation')->where('source_item_id',$source)->where('mapping','explicit_previous_item_id')))->orderByRaw("CASE WHEN kind='source' THEN 0 ELSE 1 END")->orderBy('content_index')->orderBy('id')->get()->map(fn($u)=>['unit_id'=>(int)$u->id,'kind'=>$u->kind,'original_text'=>$u->original_text,'text'=>$u->current_text??$u->original_text,'status'=>$u->status,'version'=>(int)$u->version]);
  return ['original'=>['version'=>1,'text'=>$unit->original_text,'kind'=>$unit->kind],'unit'=>Access::unit($unit),'related_units'=>$related,'revisions'=>$rows,'next_before_id'=>$rows->count()===50?$rows->last()->id:null];});
 }
 public function restore(int $lessonId,int $unitId,?object $user,?int $revisionId,int $version,string $key,?string $sessionId=null):array {
  return DB::transaction(function()use($lessonId,$unitId,$user,$revisionId,$version,$key,$sessionId){DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();$user=MutationActor::lock($user);
  Access::member($user);Access::lesson($lessonId,$user,$sessionId);$unit=DB::table('subtitle_units')->where('id',$unitId)->where('lesson_id',$lessonId)->first();if(!$unit)throw new Failure('subtitle_unavailable',404);$text=$unit->original_text;
  if($revisionId!==null){$revision=DB::table('subtitle_revisions')->where('id',$revisionId)->where('unit_id',$unitId)->first();if(!$revision)throw new Failure('revision_unavailable',404);$text=$revision->after_text;}
  return $this->revise($lessonId,$user,[['unit_id'=>$unitId,'expected_version'=>$version,'text'=>$text,'restored_revision_id'=>$revisionId]],$key,$sessionId);});
 }
 public function freeze(int $lessonId,object $admin,bool $frozen):void {
  Access::admin($admin);DB::transaction(function()use($lessonId,$admin,$frozen){DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();$admin=MutationActor::lock($admin,true);Access::lesson($lessonId,$admin);DB::table('lessons')->where('id',$lessonId)->update(['editing_frozen'=>$frozen,'updated_at'=>now()]);DB::table('audit')->insert(['user_id'=>$admin->id,'lesson_id'=>$lessonId,'action'=>$frozen?'subtitle_freeze':'subtitle_unfreeze']);Access::lessonEvent($lessonId);});
 }
 public function deletion(int $lessonId,int $unitId,object $admin,bool $deleted):array {
  Access::admin($admin);return DB::transaction(function()use($lessonId,$unitId,$admin,$deleted){DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();$admin=MutationActor::lock($admin,true);Access::lesson($lessonId,$admin);$unit=DB::table('subtitle_units')->where('id',$unitId)->where('lesson_id',$lessonId)->lockForUpdate()->first();if(!$unit)throw new Failure('subtitle_unavailable',404);DB::table('subtitle_units')->where('id',$unitId)->update(['deleted_at'=>$deleted?now():null,'updated_at'=>now()]);$updated=Access::unit(DB::table('subtitle_units')->where('id',$unitId)->first());DB::table('audit')->insert(['user_id'=>$admin->id,'lesson_id'=>$lessonId,'action'=>$deleted?'subtitle_delete':'subtitle_restore','details'=>json_encode(['unit_id'=>$unitId])]);Access::event($lessonId,'subtitle.updated',['unit'=>$updated]);return ['unit'=>$updated];});
 }
}
