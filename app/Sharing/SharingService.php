<?php
declare(strict_types=1);
namespace App\Sharing;
use App\Content\MutationActor;
use App\Domain\{Access,Failure};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Support\Str;

final class SharingService {
 /** Called only by authorized lesson creation/admin policy mutation; GET never creates authorization. */
 public static function ensure(int $lessonId):object {
  return DB::transaction(function()use($lessonId){DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();$existing=DB::table('lesson_shares')->where('lesson_id',$lessonId)->first();if($existing)return $existing;
   $token=bin2hex(random_bytes(32));$id=(string)Str::uuid();DB::table('lesson_shares')->insert(['id'=>$id,'lesson_id'=>$lessonId,'token_hash'=>hash('sha256',$token),'token_encrypted'=>Crypt::encryptString($token)]);return DB::table('lesson_shares')->where('id',$id)->first();});
 }
 public static function hasGrant(?string $sessionId,int $lessonId):bool {
  if(!$sessionId)return false;
  $grant=DB::table('share_grants as g')->join('lesson_shares as s','s.id','=','g.share_id')->join('sessions as session','session.id','=','g.session_id')->where('g.session_id',$sessionId)->where('s.lesson_id',$lessonId)->whereColumn('g.share_version','s.version')->whereNull('s.revoked_at')->where('session.last_activity','>=',time()-(int)config('session.lifetime',120)*60)->where(fn($q)=>$q->whereNull('s.expires_at')->orWhere('s.expires_at','>',now()))->select('s.id','s.version','session.payload')->first();
  if(!$grant||strlen($grant->payload)>1048576)return false;
  $payload=base64_decode($grant->payload,true);if($payload===false)return false;
  $attributes=@unserialize($payload,['allowed_classes'=>false]);
  // An erased/expired session row cannot resurrect an orphan grant merely by reusing its cookie ID.
  return is_array($attributes)&&is_array($attributes['lesson_share_grants']??null)&&($attributes['lesson_share_grants'][$grant->id]??null)===(int)$grant->version;
 }
 public static function migrateGrants(string $old,string $new):void {
  if($old===$new)return;
  // Sessions regenerate before their end-of-request save. No FK to that mutable row.
  DB::transaction(function()use($old,$new){foreach(DB::table('share_grants')->where('session_id',$old)->get() as $grant)DB::table('share_grants')->upsert([['session_id'=>$new,'share_id'=>$grant->share_id,'share_version'=>$grant->share_version]],['session_id','share_id'],['share_version']);DB::table('share_grants')->where('session_id',$old)->delete();});
 }
 public function redeem(Request $request,string $token):int {
  if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new Failure('share_invalid',404);
  $share=DB::table('lesson_shares')->where('token_hash',hash('sha256',$token))->first();if(!$share)throw new Failure('share_invalid',404);
  $this->valid($share);$lesson=DB::table('lessons as l')->join('courses as c','c.id','=','l.course_id')->where('l.id',$share->lesson_id)->where('c.enabled',true)->select('l.*')->first();
  if(!$lesson||(isset($lesson->deleted_at)&&$lesson->deleted_at!==null))throw new Failure('lesson_unavailable',404);
  $course=DB::table('courses')->where('id',$lesson->course_id)->first();if(isset($course->deleted_at)&&$course->deleted_at!==null)throw new Failure('lesson_unavailable',404);
  DB::transaction(function()use($request,$share){$current=DB::table('lesson_shares')->where('id',$share->id)->lockForUpdate()->first();$this->valid($current);if($current->version!==$share->version)throw new Failure('share_revoked',410);DB::table('share_grants')->upsert([['session_id'=>$request->session()->getId(),'share_id'=>$share->id,'share_version'=>$share->version]],['session_id','share_id'],['share_version']);});
  $request->session()->put('lesson_share_grants.'.$share->id,(int)$share->version);$request->session()->save();
  return (int)$share->lesson_id;
 }
 public function view(int $lessonId,?object $user,string $sessionId):array {
  $lesson=Access::lesson($lessonId,$user,$sessionId);$access=$lesson->guest_access??DB::table('courses')->where('id',$lesson->course_id)->value('guest_access');$url=url('/lessons/'.$lessonId);$expires=null;
  if($access==='link'){$share=DB::table('lesson_shares')->where('lesson_id',$lessonId)->first();if(!$share)throw new Failure('share_unavailable',409);$this->valid($share);$url=url('/s/'.Crypt::decryptString($share->token_encrypted));$expires=$share->expires_at;}
  return ['url'=>$url,'lesson_id'=>$lessonId,'course_name'=>$lesson->course_name,'lesson_name'=>$lesson->name,'status'=>$lesson->status,'access'=>$access,'expires_at'=>$expires];
 }
 public function reset(int $lessonId,object $admin,?string $expires):array {
  Access::admin($admin);return DB::transaction(function()use($lessonId,$admin,$expires){DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();$admin=MutationActor::lock($admin,true);Access::lesson($lessonId,$admin);$share=self::ensure($lessonId);$token=bin2hex(random_bytes(32));DB::table('lesson_shares')->where('id',$share->id)->update(['token_hash'=>hash('sha256',$token),'token_encrypted'=>Crypt::encryptString($token),'version'=>$share->version+1,'expires_at'=>$expires,'revoked_at'=>null,'updated_at'=>now()]);DB::table('share_grants')->where('share_id',$share->id)->delete();DB::table('audit')->insert(['user_id'=>$admin->id,'lesson_id'=>$lessonId,'action'=>'share_reset']);Access::lessonEvent($lessonId);return ['url'=>url('/s/'.$token),'expires_at'=>$expires];});
 }
 public function revoke(int $lessonId,object $admin):void {
  Access::admin($admin);DB::transaction(function()use($lessonId,$admin){if(!DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first())throw new Failure('lesson_unavailable',404);$admin=MutationActor::lock($admin,true);DB::table('lesson_shares')->where('lesson_id',$lessonId)->update(['revoked_at'=>now(),'updated_at'=>now()]);DB::table('audit')->insert(['user_id'=>$admin->id,'lesson_id'=>$lessonId,'action'=>'share_revoked']);Access::lessonEvent($lessonId);});
 }
 private function valid(?object $share):void {if(!$share)throw new Failure('share_invalid',404);if($share->revoked_at)throw new Failure('share_revoked',410);if($share->expires_at&&strtotime($share->expires_at)<=time())throw new Failure('share_expired',410);}
}
