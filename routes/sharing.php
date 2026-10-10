<?php
use App\Accounts\DeviceAuth;
use App\Domain\{Access,Failure};
use App\Sharing\SharingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
Route::get('/s/{token}',function(Request $r,string $token){try{$id=app(SharingService::class)->redeem($r,$token);return redirect('/lessons/'.$id)->withHeaders(['Referrer-Policy'=>'no-referrer','Cache-Control'=>'no-store']);}catch(Failure $e){return redirect('/share-unavailable?code='.rawurlencode($e->errorCode))->withHeaders(['Referrer-Policy'=>'no-referrer','Cache-Control'=>'no-store']);}});
Route::get('/api/lessons/{lesson}/share',fn(Request $r,int $lesson)=>app(SharingService::class)->view($lesson,Access::user($r->session()->get('uid')),$r->session()->getId()));
Route::post('/api/admin/lessons/{lesson}/share/reset',function(Request $r,int $lesson){$u=Access::user($r->session()->get('uid'));Access::admin($u);DeviceAuth::requireRecent($r);$v=$r->validate(['confirmed'=>'required|accepted','expires_at'=>'nullable|date|after:now']);if($r->input('confirmed')!==true)throw new Failure('confirmation_required');return app(SharingService::class)->reset($lesson,$u,isset($v['expires_at'])?\Carbon\CarbonImmutable::parse($v['expires_at'])->utc()->toIso8601String():null);});
Route::post('/api/admin/lessons/{lesson}/share/revoke',function(Request $r,int $lesson){$u=Access::user($r->session()->get('uid'));Access::admin($u);DeviceAuth::requireRecent($r);if($r->input('confirmed')!==true)throw new Failure('confirmation_required');app(SharingService::class)->revoke($lesson,$u);return ['revoked'=>true];});
