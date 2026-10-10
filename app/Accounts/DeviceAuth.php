<?php
namespace App\Accounts;
use App\Domain\{Access,Failure};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cookie,DB,Hash,RateLimiter};
use Illuminate\Support\Str;
final class DeviceAuth {
 public const COOKIE='classroom_remember';
 public static function safe(?object $u):?array{return $u?['id'=>$u->id,'name'=>$u->name,'role'=>$u->role,'must_change_password'=>(bool)$u->must_change_password]:null;}
 public static function requireRecent(Request $r):void {Access::member(Access::user((int)$r->session()->get('uid')));if((int)$r->session()->get('reauth_until')<time())throw new Failure('reauth_required',403);}
 public static function forgetCookie():void{Cookie::queue(Cookie::forget(self::COOKIE,'/'));}
 public static function lockSession(string $id):void{DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',['classroom.session:'.$id]);}
 public static function isRevoked(string $id):bool{return DB::table('session_revocations')->where('id_hash',hash('sha256',$id))->where('expires_at','>',now())->exists();}
 public static function markSessions(array $ids):void{sort($ids,SORT_STRING);foreach(array_unique($ids)as$id){self::lockSession($id);DB::table('session_revocations')->upsert([['id_hash'=>hash('sha256',$id),'expires_at'=>now()->addMinutes(max(120,(int)config('session.lifetime',120))+30)]],['id_hash'],['expires_at']);}}
 public static function discardSessions(array $ids):void{if(!$ids)return;DB::transaction(function()use($ids){self::markSessions($ids);if(DB::getSchemaBuilder()->hasTable('share_grants'))DB::table('share_grants')->whereIn('session_id',$ids)->delete();DB::table('ws_tickets')->whereIn('session_id',$ids)->delete();DB::table('sessions')->whereIn('id',$ids)->delete();},3);}
 private static function cookie(string $id,string $validator,object $device):void{Cookie::queue(cookie(self::COOKIE,$id.'.'.$validator,max(1,(int)ceil((strtotime($device->expires_at)-time())/60)),'/',null,(bool)config('session.secure'),true,false,'lax'));}
 public static function regenerate(Request $r):void{$old=$r->session()->getId();DB::transaction(function()use($r,$old){self::markSessions([$old]);$r->session()->regenerate(false);if(class_exists(\App\Sharing\SharingService::class))\App\Sharing\SharingService::migrateGrants($old,$r->session()->getId());DB::table('ws_tickets')->where('session_id',$old)->delete();DB::table('sessions')->where('id',$old)->delete();},3);}
 public function login(Request $r,array $v):array {
  $key='login:'.hash('sha256',$r->ip().'|'.$v['student_id']);if(RateLimiter::tooManyAttempts($key,5))throw new Failure('login_rate_limited',429);RateLimiter::hit($key,60);
  return DB::transaction(function()use($r,$v,$key){$u=DB::table('users')->where('student_id',$v['student_id'])->lockForUpdate()->first();if(!$u||$u->disabled||!Hash::check($v['password'],$u->password))throw new Failure('invalid_credentials',401);RateLimiter::clear($key);
  $this->logoutDevice($r,false);self::regenerate($r);$r->session()->put(['uid'=>(int)$u->id,'auth_version'=>(int)$u->auth_version,'reauth_until'=>time()+900,'reauth_at'=>time()]);
  if($v['remember']??false){$id=(string)Str::uuid();$validator=bin2hex(random_bytes(32));DB::table('login_devices')->insert(['id'=>$id,'user_id'=>$u->id,'validator_hash'=>hash('sha256',$validator),'expires_at'=>now()->addDays(90),'user_agent'=>mb_substr((string)$r->userAgent(),0,300)]);$r->session()->put('device_id',$id);self::cookie($id,$validator,DB::table('login_devices')->find($id));}
  return ['user'=>self::safe($u),'csrf_token'=>csrf_token()];},3);
 }
 public function restore(Request $r):void {
  $cookie=(string)$r->cookie(self::COOKIE,'');if($cookie==='')return;
  if(!preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.([0-9a-f]{64})$/D',$cookie,$m)){self::forgetCookie();return;}
  DB::transaction(function()use($r,$m){$first=DB::table('login_devices')->find($m[1]);$u=$first?DB::table('users')->where('id',$first->user_id)->where('disabled',false)->lockForUpdate()->first():null;$d=$u?DB::table('login_devices')->where('id',$m[1])->where('user_id',$u->id)->lockForUpdate()->first():null;
   if(!$d||!$u||$d->revoked_at||strtotime($d->expires_at)<=time()){self::forgetCookie();return;}
   $hash=hash('sha256',$m[2]);$current=hash_equals($d->validator_hash,$hash);$grace=$d->previous_validator_hash&&$d->previous_valid_until&&strtotime($d->previous_valid_until)>=time()&&hash_equals($d->previous_validator_hash,$hash);
   if(!$current&&!$grace){DB::table('login_devices')->where('id',$d->id)->update(['revoked_at'=>now()]);self::discardSessions(DB::table('sessions')->where('device_id',$d->id)->pluck('id')->all());self::forgetCookie();return;}
   self::regenerate($r);$r->session()->put(['uid'=>(int)$u->id,'auth_version'=>(int)$u->auth_version,'device_id'=>$d->id]);$r->session()->forget(['reauth_until','reauth_at']);
   if($current){$validator=bin2hex(random_bytes(32));DB::table('login_devices')->where('id',$d->id)->update(['previous_validator_hash'=>$d->validator_hash,'previous_valid_until'=>now()->addSeconds(30),'validator_hash'=>hash('sha256',$validator),'last_used_at'=>now()]);self::cookie($d->id,$validator,$d);}
  },3);
 }
 public function logoutDevice(Request $r,bool $invalidate=true):void {
  $id=$r->session()->get('device_id');if(!$id&&preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.([0-9a-f]{64})$/D',(string)$r->cookie(self::COOKIE,''),$m)){$d=DB::table('login_devices')->find($m[1]);$h=hash('sha256',$m[2]);if($d&&(hash_equals($d->validator_hash,$h)||($d->previous_validator_hash&&hash_equals($d->previous_validator_hash,$h))))$id=$d->id;}if($id){DB::table('login_devices')->where('id',$id)->update(['revoked_at'=>now()]);$sids=DB::table('sessions')->where('device_id',$id)->pluck('id')->all();if(!$invalidate)$sids=array_values(array_diff($sids,[$r->session()->getId()]));self::discardSessions($sids);}
  if($invalidate)self::discardSessions([$r->session()->getId()]);else DB::table('ws_tickets')->where('session_id',$r->session()->getId())->delete();self::forgetCookie();$r->session()->forget(['uid','auth_version','device_id','reauth_until','reauth_at']);if($invalidate){$r->session()->invalidate();$r->session()->regenerateToken();}
 }
 public static function revokeUser(int $id):int{return DB::transaction(function()use($id){DB::table('users')->where('id',$id)->increment('auth_version');DB::table('login_devices')->where('user_id',$id)->whereNull('revoked_at')->update(['revoked_at'=>now()]);self::discardSessions(DB::table('sessions')->where('user_id',$id)->pluck('id')->all());DB::table('ws_tickets')->where('user_id',$id)->delete();return (int)DB::table('users')->where('id',$id)->value('auth_version');});}
 public function changePassword(Request $r,array $v):array {
  $id=(int)$r->session()->get('uid');$version=DB::transaction(function()use($r,$id,$v){$u=DB::table('users')->where('id',$id)->where('disabled',false)->lockForUpdate()->first();if(!$u||(int)$u->auth_version!==(int)$r->session()->get('auth_version'))throw new Failure('login_required',401);
   if(!$u->must_change_password&&!Hash::check((string)($v['current_password']??''),$u->password))throw new Failure('invalid_credentials',401);
   if($v['password']===$u->student_id||$v['password']===mb_substr($u->student_id,-6)||($u->must_change_password&&Hash::check($v['password'],$u->password)))throw new Failure('password_predictable',422);
   self::regenerate($r);DB::table('users')->where('id',$id)->update(['password'=>Hash::make($v['password']),'must_change_password'=>false,'updated_at'=>now()]);$version=self::revokeUser($id);self::audit($id,'user.password_changed');return $version;});
  self::forgetCookie();$r->session()->put(['uid'=>$id,'auth_version'=>$version,'reauth_until'=>time()+900,'reauth_at'=>time()]);$r->session()->forget('device_id');
  return ['user'=>self::safe(Access::user($id)),'csrf_token'=>csrf_token()];
 }
 public static function audit(int $user,string $action,array $details=[]):void{DB::table('audit')->insert(['user_id'=>$user,'action'=>$action,'details'=>json_encode($details,JSON_UNESCAPED_UNICODE)]);}
}
