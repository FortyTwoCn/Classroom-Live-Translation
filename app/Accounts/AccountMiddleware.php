<?php
namespace App\Accounts;
use App\Domain\Access;
use Closure;
use Illuminate\Http\Request;
final class AccountMiddleware {
 public function handle(Request $r,Closure $next){
  if(DeviceAuth::isRevoked($r->session()->getId())){$r->session()->invalidate();$r->session()->regenerateToken();}
  $uid=$r->session()->get('uid');
  if($uid){$u=Access::user((int)$uid);$device=$r->session()->get('device_id');$valid=$u&&(int)$r->session()->get('auth_version')===(int)$u->auth_version;if($valid&&$device)$valid=\Illuminate\Support\Facades\DB::table('login_devices')->where('id',$device)->where('user_id',$uid)->whereNull('revoked_at')->where('expires_at','>',now())->exists();if(!$valid){DeviceAuth::discardSessions([$r->session()->getId()]);$r->session()->invalidate();$r->session()->regenerateToken();DeviceAuth::forgetCookie();}}
  elseif(!in_array($r->path(),['api/login','api/logout'],true)){app(DeviceAuth::class)->restore($r);}
  return $next($r);
 }
}
