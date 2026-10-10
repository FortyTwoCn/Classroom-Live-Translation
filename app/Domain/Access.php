<?php
namespace App\Domain;
use Illuminate\Support\Facades\DB;
final class Access {
 public static function user(?int $id): ?object { return $id ? DB::table('users')->where('id',$id)->where('disabled',false)->first() : null; }
 public static function member(?object $user): void { if (!$user) throw new Failure('login_required',401); if ($user->must_change_password) throw new Failure('password_change_required',403); }
 public static function admin(?object $user): void { self::member($user); if ($user->role!=='admin') throw new Failure('forbidden',403); }
 public static function lesson(int $id, ?object $user, ?string $sessionId=null): object {
  $l=DB::table('lessons as l')->join('courses as c','c.id','=','l.course_id')->leftJoin('users as recorder','recorder.id','=','l.recorder_user_id')->where('l.id',$id)->whereNull('l.deleted_at')->whereNull('c.deleted_at')->where('c.enabled',true)->select('l.*','c.name as course_name','c.guest_access','c.allow_audio_download','recorder.name as recorder_name')->first();
  if (!$l) throw new Failure('lesson_unavailable',404);
  if ((!$user || $user->must_change_password) && $l->guest_access!=='public' && !\App\Sharing\SharingService::hasGrant($sessionId,$id)) throw new Failure('forbidden',403);
  return $l;
 }
 public static function publicLesson(object $l): array { $a=(array)$l; if(isset($l->guest_access))$a['access_mode']=$l->guest_access; unset($a['recorder_connection'],$a['idempotency_key'],$a['guest_access'],$a['lease_until'],$a['model_settings_version'],$a['storage_version']); return $a; }
 public static function unit(object $u): array { $a=(array)$u; $a['text']=$u->current_text??$u->original_text; unset($a['current_text']); return $a; }
 public static function event(int $lessonId,string $type,array $payload): int { return DB::transaction(function()use($lessonId,$type,$payload){ DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first(); return DB::table('lesson_events')->insertGetId(['lesson_id'=>$lessonId,'event_type'=>$type,'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE)] ,'seq'); }); }
 public static function lessonEvent(int $id): void { $l=DB::table('lessons as l')->join('courses as c','c.id','=','l.course_id')->leftJoin('users as recorder','recorder.id','=','l.recorder_user_id')->where('l.id',$id)->select('l.*','c.name as course_name','c.guest_access','c.allow_audio_download','recorder.name as recorder_name')->first(); if($l)self::event($id,'lesson.updated',['lesson'=>self::publicLesson($l)]); }
}
