<?php
declare(strict_types=1);
namespace App\Storage;
use Illuminate\Support\Facades\{Crypt,DB};
use RuntimeException;
final class StorageRegistry {
 public static function register(array $values):string {
  $c=new OssConfiguration($values);$version=$c->version();
  $payload=['endpoint'=>$c->endpoint,'region'=>$c->region,'bucket'=>$c->bucket,'key'=>$c->key,'secret'=>$c->secret,'token'=>$c->token,'prefix'=>$c->prefix,'presign_seconds'=>$c->presignSeconds];
  DB::table('storage_versions')->upsert([['version'=>$version,'payload_encrypted'=>Crypt::encryptString(json_encode($payload,JSON_THROW_ON_ERROR)),'created_at'=>now(),'updated_at'=>now()]],['version'],['payload_encrypted','updated_at']);return $version;
 }
 public static function configuration(string $version):OssConfiguration {
  try{$payload=DB::table('storage_versions')->where('version',$version)->value('payload_encrypted');if(!$payload)throw new RuntimeException();$c=new OssConfiguration(json_decode(Crypt::decryptString($payload),true,32,JSON_THROW_ON_ERROR));if(!hash_equals($version,$c->version()))throw new RuntimeException();return $c;}catch(\Throwable){throw new RuntimeException('storage_configuration_version_unavailable');}
 }
 public static function resolve(string $version):AwsS3OssStorage{return app(AwsS3OssStorage::class,['configuration'=>self::configuration($version)]);}
 /** Historical rows are inferred only from their own recorded identity, never current settings. */
 public static function lessonVersion(object $lesson):string {
  if($lesson->storage_version??null){self::configuration($lesson->storage_version);return $lesson->storage_version;}
  $versions=DB::table('audio_segments')->where('lesson_id',$lesson->id)->distinct()->pluck('storage_version');if($versions->count()!==1)throw new RuntimeException('storage_configuration_version_unavailable');$version=(string)$versions[0];self::configuration($version);DB::table('lessons')->where('id',$lesson->id)->whereNull('storage_version')->update(['storage_version'=>$version]);return $version;
 }
}
