<?php
namespace App\Accounts;
use App\Domain\{Access,Failure};
use Illuminate\Support\Facades\{DB,Hash};
final class Management {
 private static function normalizeUser(array $v):array{foreach(['name','student_id']as$field)if(isset($v[$field])){$v[$field]=mb_trim($v[$field]);$min=$field==='name'?1:6;$max=$field==='name'?200:100;if(mb_strlen($v[$field])<$min||mb_strlen($v[$field])>$max||preg_match($field==='name'?'/\p{C}/u':'/[\p{C}\p{Z}\s]/u',$v[$field]))throw new Failure($field==='name'?'import_name_invalid':'import_student_id_invalid',422,['min'=>$min]);}return $v;}
 public static function user(object $u):array{$a=(array)$u;unset($a['password'],$a['auth_version']);return $a;}
 public static function createUser(int $admin,array $v):array {
  $v=self::normalizeUser($v);
  if(DB::table('users')->where('student_id',$v['student_id'])->exists())throw new Failure('student_id_exists',409);
  try{return DB::transaction(function()use($admin,$v){$id=DB::table('users')->insertGetId(['name'=>$v['name'],'student_id'=>$v['student_id'],'password'=>Hash::make(mb_substr($v['student_id'],-6)),'role'=>'user','must_change_password'=>true]);DeviceAuth::audit($admin,'user.created',['user_id'=>$id]);return self::user(DB::table('users')->find($id));});}catch(\Illuminate\Database\QueryException $e){if($e->getCode()==='23505')throw new Failure('student_id_exists',409);throw $e;}
 }
 public static function updateUser(int $admin,int $id,array $v):array {
  $v=self::normalizeUser($v);
  return DB::transaction(function()use($admin,$id,$v){DB::select('SELECT pg_advisory_xact_lock(1702003)');$u=DB::table('users')->where('id',$id)->lockForUpdate()->first();if(!$u)throw new Failure('user_unavailable',404);
   if($u->role==='admin'&&!$u->disabled&&(($v['role']??$u->role)!=='admin'||($v['disabled']??false))&&DB::table('users')->where('role','admin')->where('disabled',false)->count()<=1)throw new Failure('last_admin_required',409);
   if(isset($v['student_id'])&&DB::table('users')->where('student_id',$v['student_id'])->where('id','<>',$id)->exists())throw new Failure('student_id_exists',409);
   $changed=array_intersect_key($v,array_flip(['name','student_id','role','disabled']));$changed['updated_at']=now();DB::table('users')->where('id',$id)->update($changed);
   if(isset($v['role'])||isset($v['disabled'])||isset($v['student_id']))DeviceAuth::revokeUser($id);
   DeviceAuth::audit($admin,'user.updated',['user_id'=>$id,'fields'=>array_keys($v)]);return self::user(DB::table('users')->find($id));
  });
 }
 public static function resetPassword(int $admin,int $id):void {DB::transaction(function()use($admin,$id){$u=DB::table('users')->where('id',$id)->lockForUpdate()->first();if(!$u)throw new Failure('user_unavailable',404);self::normalizeUser(['student_id'=>$u->student_id]);DB::table('users')->where('id',$id)->update(['password'=>Hash::make(mb_substr($u->student_id,-6)),'must_change_password'=>true,'updated_at'=>now()]);DeviceAuth::revokeUser($id);DeviceAuth::audit($admin,'user.password_reset',['user_id'=>$id]);});}
 public static function course(int $admin,?int $id,array $v):array {
  if(array_key_exists('description',$v))$v['description']=$v['description']??'';
  return DB::transaction(function()use($admin,$id,$v){$c=$id?DB::table('courses')->where('id',$id)->whereNull('deleted_at')->lockForUpdate()->first():null;if($id&&!$c)throw new Failure('course_unavailable',404);
   if(array_key_exists('archived',$v)){$v['archived_at']=$v['archived']?now():null;unset($v['archived']);}if(!$id){$id=DB::table('courses')->insertGetId($v);}else{DB::table('courses')->where('id',$id)->update([...$v,'updated_at'=>now()]);}
   foreach(DB::table('lessons')->where('course_id',$id)->pluck('id')as$lid){if(($v['guest_access']??$c?->guest_access)==='link')\App\Sharing\SharingService::ensure((int)$lid);Access::lessonEvent((int)$lid);}
   DeviceAuth::audit($admin,$c?'course.updated':'course.created',['course_id'=>$id,'fields'=>array_keys($v)]);return (array)DB::table('courses')->find($id);
  });
 }
 public static function deleteCourse(int $admin,int $id):void {DB::transaction(function()use($admin,$id){$c=DB::table('courses')->where('id',$id)->whereNull('deleted_at')->lockForUpdate()->first();if(!$c)throw new Failure('course_unavailable',404);if(DB::table('lessons')->where('course_id',$id)->where('status','<>','ended')->exists())throw new Failure('course_has_active_lesson',409);DB::table('courses')->where('id',$id)->update(['enabled'=>false,'deleted_at'=>now(),'updated_at'=>now()]);foreach(DB::table('lessons')->where('course_id',$id)->pluck('id')as$lid)Access::lessonEvent((int)$lid);DeviceAuth::audit($admin,'course.deleted',['course_id'=>$id,'lesson_count'=>DB::table('lessons')->where('course_id',$id)->count()]);});}
}

