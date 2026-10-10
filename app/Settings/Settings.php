<?php
declare(strict_types=1);
namespace App\Settings;
use App\Domain\Failure;
use App\Content\MutationActor;
use App\Storage\{OssConfiguration,StorageRegistry};
use App\Translation\QwenConfig;
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;

/** Secret configuration is encrypted at rest and never used as API data. */
final class Settings {
 public static function defaults():array {return [
  'default_locale'=>'zh-CN','time_zone'=>'Asia/Shanghai','retention_days'=>0,'auto_purge'=>false,'audio_download'=>false,
  'max_lesson_seconds'=>(int)config('classroom.max_seconds',5400),'max_active_lessons'=>(int)config('classroom.max_active',3),
  'max_daily_audio_seconds'=>32400,'warning_ratio'=>0.8,'rotation_soft_seconds'=>600,'rotation_hard_seconds'=>720,
  'min_disk_bytes'=>(int)config('classroom.min_disk_bytes',536870912),'max_import_bytes'=>4194304,'max_import_rows'=>2000,'prices'=>[],
 ];}
 public static function values(string $kind):array {
  $row=DB::table('settings_versions')->where('kind',$kind)->where('active',true)->first();
  if($row)return self::decode($row);
  return match($kind){'model'=>config('classroom.qwen',[]),'oss'=>['type'=>'aliyun_s3',...config('classroom.oss',[])],'policies'=>self::defaults(),default=>throw new Failure('settings_kind_invalid',422)};
 }
 public static function policies():array {return [...self::defaults(),...self::values('policies')];}
 public static function version(string $kind):?string {return DB::table('settings_versions')->where('kind',$kind)->where('active',true)->value('id');}
 public static function decode(object $row):array {
  try{$values=json_decode(Crypt::decryptString($row->payload_encrypted),true,32,JSON_THROW_ON_ERROR);if(!is_array($values))throw new \RuntimeException();return $values;}
  catch(\Throwable){throw new Failure('settings_decryption_failed',503);}
 }
 public static function byVersion(string $id,string $kind):array {$row=DB::table('settings_versions')->where(['id'=>$id,'kind'=>$kind])->first();if(!$row)throw new Failure('settings_version_unavailable',503);return self::decode($row);}
 public static function adminView():array {
  $model=self::values('model');$oss=self::values('oss');
  $model['api_key_set']=!empty($model['api_key']);unset($model['api_key'],$model['allow_test_endpoint']);
  foreach(['key','secret','token']as$key){$oss[$key.'_set']=!empty($oss[$key]);unset($oss[$key]);}
  return ['model'=>$model,'oss'=>$oss,'policies'=>self::policies(),'versions'=>['model'=>self::version('model'),'oss'=>self::version('oss'),'policies'=>self::version('policies')]];
 }
 public static function publicView():array {return array_intersect_key(self::policies(),array_flip(['default_locale','time_zone','retention_days','audio_download','max_lesson_seconds']));}
 public static function save(string $kind,array $input,?string $expected,object $actor):array {
  return DB::transaction(function()use($kind,$input,$expected,$actor){
   DB::select('SELECT pg_advisory_xact_lock(1702008)');$actor=MutationActor::lock($actor,true);
   if(self::version($kind)!==$expected)throw new Failure('settings_version_conflict',409);
   $values=$kind==='policies'?self::policies():self::values($kind);$allowed=match($kind){'model'=>['model','region','workspace_id','endpoint','api_key'],'oss'=>['type','endpoint','region','bucket','key','secret','token','prefix','presign_seconds','clear_token'],'policies'=>array_keys(self::defaults()),default=>throw new Failure('settings_kind_invalid',422)};
   if(array_diff(array_keys($input),$allowed))throw new Failure('settings_fields_invalid',422);
   if($kind==='oss'){try{$old=new OssConfiguration($values);if(!DB::table('storage_versions')->where('version',$old->version())->exists())StorageRegistry::register($values);}catch(\InvalidArgumentException){/* No valid previous environment destination to register. */}}
   foreach($input as$key=>$value){if(in_array($key,['api_key','key','secret','token'],true)&&($value===null||$value===''))continue;$values[$key]=$value;}
   if($kind==='oss'&&!empty($input['clear_token']))$values['token']=null;unset($values['clear_token']);
   self::validate($kind,$values);
   if($kind==='oss')StorageRegistry::register($values);
   DB::table('settings_versions')->where('kind',$kind)->where('active',true)->update(['active'=>false]);$id=(string)Str::uuid();
   DB::table('settings_versions')->insert(['id'=>$id,'kind'=>$kind,'payload_encrypted'=>Crypt::encryptString(json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),'actor_id'=>$actor->id]);
   DB::table('audit')->insert(['user_id'=>$actor->id,'action'=>'settings.updated','details'=>json_encode(['kind'=>$kind,'version'=>$id,'fields'=>array_keys($input)])]);return self::adminView();
  });
 }
 public static function validate(string $kind,array &$values):void {
  try{
   if($kind==='model'){foreach(['model','region','workspace_id','endpoint','api_key']as$key)if(isset($values[$key])&&!is_string($values[$key]))throw new \InvalidArgumentException();$cfg=QwenConfig::fromArray($values,app()->environment('testing'));$cfg->validate();$cfg->sessionUpdate('zh');return;}
   if($kind==='oss'){foreach(['type','endpoint','region','bucket','key','secret','token','prefix']as$key)if(isset($values[$key])&&!is_string($values[$key]))throw new \InvalidArgumentException();if(($values['type']??'aliyun_s3')!=='aliyun_s3')throw new \InvalidArgumentException();new OssConfiguration($values);return;}
   if($kind!=='policies')throw new \InvalidArgumentException();
   if(!in_array($values['default_locale'],['zh-CN','en','uk'],true)||!in_array($values['time_zone'],\DateTimeZone::listIdentifiers(),true))throw new \InvalidArgumentException();
   foreach(['auto_purge','audio_download']as$key)if(!is_bool($values[$key]))throw new \InvalidArgumentException();
   foreach(['max_import_bytes'=>[65536,4194304],'max_import_rows'=>[1,2000],'retention_days'=>[0,36500],'max_lesson_seconds'=>[60,86400],'max_active_lessons'=>[1,20],'max_daily_audio_seconds'=>[60,1728000],'min_disk_bytes'=>[16777216,1099511627776],'rotation_soft_seconds'=>[30,3600],'rotation_hard_seconds'=>[40,7200]]as$key=>[$min,$max])if(!is_int($values[$key])||$values[$key]<$min||$values[$key]>$max)throw new \InvalidArgumentException();
   if(!is_numeric($values['warning_ratio'])||$values['warning_ratio']<0.1||$values['warning_ratio']>=1||$values['rotation_hard_seconds']<=$values['rotation_soft_seconds']||($values['auto_purge']&&$values['retention_days']===0))throw new \InvalidArgumentException();
   $values['prices']=\App\Reliability\Usage::validatePrices($values['prices']);
  }catch(\Throwable $e){if($e instanceof Failure)throw $e;throw new Failure('settings_values_invalid',422,['kind'=>$kind]);}
 }
 public static function ensureVersion(string $kind):string {
  return DB::transaction(function()use($kind){DB::select('SELECT pg_advisory_xact_lock(1702008)');$id=self::version($kind);if($id)return $id;$values=$kind==='policies'?self::policies():self::values($kind);self::validate($kind,$values);if($kind==='oss')StorageRegistry::register($values);$id=(string)Str::uuid();DB::table('settings_versions')->insert(['id'=>$id,'kind'=>$kind,'payload_encrypted'=>Crypt::encryptString(json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))]);return $id;});
 }
 public static function freezeModel(int $lessonId):array {
  DB::select('SELECT pg_advisory_xact_lock(1702008)');$lesson=DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();if(!$lesson)throw new Failure('lesson_unavailable',404);
  $id=$lesson->model_settings_version??self::ensureVersion('model');if(!$lesson->model_settings_version)DB::table('lessons')->where('id',$lessonId)->update(['model_settings_version'=>$id]);return self::byVersion($id,'model');
 }
}
