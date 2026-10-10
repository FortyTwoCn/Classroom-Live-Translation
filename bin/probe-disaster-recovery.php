<?php
declare(strict_types=1);
// Populated synthetic integration DB -> fresh DB + storage. Never overwrites source objects.
require __DIR__.'/bootstrap.php';
use App\Storage\{StorageRegistry,SegmentRecovery,PcmArchiveAssembler,RecordingPaths,OssConfiguration};
use Illuminate\Support\Facades\{DB,Artisan,Crypt};
use Symfony\Component\Process\Process;

$options=getopt('', ['source:','lesson:','metadata::']);
$sourceName=(string)($options['source']??'');$lessonId=(int)($options['lesson']??0);
if(!preg_match('/^classroom_integration_[a-f0-9]{12}$/D',$sourceName)||$lessonId<1){fwrite(STDERR,"Usage: php bin/probe-disaster-recovery.php --source=classroom_integration_<12hex> --lesson=<id> [--metadata=<private-json>]\n");exit(2);}
$connection=config('database.connections.pgsql');
if(!in_array($connection['host'],['127.0.0.1','localhost'],true)){fwrite(STDERR,"This synthetic drill requires a local PostgreSQL server.\n");exit(2);}
$workspace=dirname(__DIR__,3);$nonce=bin2hex(random_bytes(8));$destination='classroom_disaster_'.$nonce;$work=$workspace.'/work/disaster-recovery-'.$nonce;
$pg=env('PG_BIN_DIR',$workspace.'/work/runtime/pg/pgsql/bin');$suffix=PHP_OS_FAMILY==='Windows'?'.exe':'';
$reportPath=dirname(__DIR__).'/reports/disaster-recovery-real.json';$started=hrtime(true);
$report=['test_type'=>'populated_postgresql_historical_oss_disaster_recovery','status'=>'running','audio'=>'synthetic integration capture; no new model or microphone request','source_database'=>$sourceName,'restored_database'=>$destination,'source_lesson_id'=>$lessonId,'started_at'=>gmdate(DATE_ATOM),'steps'=>[],'source_database_modified'=>false,'existing_oss_objects_modified'=>false];
$originalKey=(string)config('app.key');$previousKeys=config('app.previous_keys',[]);$originalStorage=storage_path();$cloudProof=null;$cloudStore=null;
$step=function(string $name,callable $fn)use(&$report){$t=hrtime(true);$r=$fn();$report['steps'][]=['step'=>$name,'status'=>'passed','elapsed_ms'=>(int)((hrtime(true)-$t)/1000000)];return $r;};
$pdo=function(string $database)use($connection):PDO{$p=new PDO('pgsql:host='.$connection['host'].';port='.$connection['port'].';dbname='.$database,$connection['username'],$connection['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->exec("SET TIME ZONE 'UTC'");return $p;};
$catalog=function(PDO $p):array{$counts=[];$hashes=[];foreach($p->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")as$row){$table=$row['tablename'];$quoted='"'.str_replace('"','""',$table).'"';$counts[$table]=(int)$p->query('SELECT count(*) FROM public.'.$quoted)->fetchColumn();$h=hash_init('sha256');foreach($p->query('SELECT row_to_json(t)::text AS data FROM public.'.$quoted.' t ORDER BY row_to_json(t)::text')as$data)hash_update($h,$data['data']."\n");$hashes[$table]=hash_final($h);}return['table_counts'=>$counts,'table_row_sha256'=>$hashes,'constraints'=>(int)$p->query("SELECT count(*) FROM pg_constraint WHERE connamespace='public'::regnamespace")->fetchColumn(),'indexes'=>(int)$p->query("SELECT count(*) FROM pg_indexes WHERE schemaname='public'")->fetchColumn()];};
$resetKey=function(string $key,array $previous=[]){config(['app.key'=>$key,'app.previous_keys'=>$previous]);app()->forgetInstance('encrypter');Crypt::clearResolvedInstance('encrypter');};
$source=null;
try{
 foreach(['pg_dump','pg_restore']as$binary)if(!is_file($pg.'/'.$binary.$suffix))throw new RuntimeException('postgres_backup_binary_unavailable');
 if(!is_file((string)config('classroom.ffmpeg'))||!is_file((string)config('classroom.ffprobe')))throw new RuntimeException('ffmpeg_binary_unavailable');
 if($originalKey==='')throw new RuntimeException('master_key_unavailable');
 if(!mkdir($work,0700,true))throw new RuntimeException('private_recovery_directory_unavailable');
 foreach(['app','logs','framework/cache/data','framework/sessions','framework/views']as$dir)mkdir($work.'/storage/'.$dir,0700,true);
 $source=$pdo($sourceName);$source->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
 $before=$step('snapshot_populated_source_read_only',function()use($source,$lessonId,$catalog){$query=$source->prepare('SELECT status,media_samples FROM lessons WHERE id=?');$query->execute([$lessonId]);$l=$query->fetch(PDO::FETCH_ASSOC);if(!$l||$l['status']!=='ended'||(int)$l['media_samples']<=0)throw new RuntimeException('source_fixture_not_ended_with_audio');return $catalog($source);});
 $snapshot=$source->query('SELECT pg_export_snapshot()')->fetchColumn();$backup=$work.'/database.private.dump';
 $run=function(string $binary,array $args)use($pg,$suffix,$connection,$work){$p=new Process([$pg.'/'.$binary.$suffix,...$args],$work,['PGPASSWORD'=>$connection['password']]);$p->setTimeout(180);$p->run();if(!$p->isSuccessful())throw new RuntimeException('postgres_'.$binary.'_failed');};
 $args=['-h',$connection['host'],'-p',(string)$connection['port'],'-U',$connection['username']];
 $step('pg_dump_consistent_exported_snapshot',fn()=>$run('pg_dump',[...$args,'-d',$sourceName,'--format=custom','--snapshot='.$snapshot,'--file='.$backup]));$source->commit();
 $report['backup']=['bytes'=>filesize($backup),'sha256'=>hash_file('sha256',$backup),'retention'=>'private work directory only'];
 $step('create_only_new_random_restore_database',function()use($pdo,$destination){$admin=$pdo('postgres');$q=$admin->prepare('SELECT 1 FROM pg_database WHERE datname=?');$q->execute([$destination]);if($q->fetchColumn()!==false)throw new RuntimeException('restore_database_already_exists');$admin->exec('CREATE DATABASE "'.$destination.'"');});
 $step('pg_restore_without_source_writes',fn()=>$run('pg_restore',[...$args,'-d',$destination,'--no-owner','--no-privileges','--exit-on-error',$backup]));
 $after=$step('verify_all_rows_and_schema_before_migrations',fn()=>$catalog($pdo($destination)));
 if($before!==$after)throw new RuntimeException('restored_database_content_mismatch');
 $report['database']=['status'=>'passed','table_count'=>count($before['table_counts']),'constraints'=>$before['constraints'],'indexes'=>$before['indexes'],'source_snapshot'=>$before,'restored_snapshot'=>$after,'all_row_hashes_match'=>true,'fresh_database_retained'=>true];
 config(['database.connections.pgsql.database'=>$destination]);DB::purge('pgsql');
 app()->useStoragePath($work.'/storage');
 $step('migrate_restored_database_to_current_schema',function(){if(Artisan::call('migrate',['--force'=>true])!==0)throw new RuntimeException('restored_schema_migration_failed');});
 $versions=DB::table('audio_segments')->where('lesson_id',$lessonId)->distinct()->pluck('storage_version')->all();
 if(count($versions)!==1)throw new RuntimeException('source_history_versions_ambiguous');$version=$versions[0];
 $originalEncrypted=DB::table('storage_versions')->where('version',$version)->exists();
 if(!$originalEncrypted){$values=config('classroom.oss');$metadata=$options['metadata']??null;if($metadata){$m=json_decode(file_get_contents($metadata),true,16,JSON_THROW_ON_ERROR);if(($m['database']??null)!==$sourceName)throw new RuntimeException('legacy_metadata_database_mismatch');$values['prefix']=$m['prefix']??'';}$cfg=new OssConfiguration($values);if($cfg->version()!==$version)throw new RuntimeException('legacy_history_configuration_unavailable');$step('register_explicit_matching_legacy_configuration',fn()=>StorageRegistry::register($values));}
 $step('resolve_original_historical_oss_configuration',fn()=>StorageRegistry::configuration($version));
 $report['historical_configuration']=['version'=>$version,'original_encrypted_row_present'=>$originalEncrypted,'env_fallback_used'=>false];
 if(PHP_OS_FAMILY==='Windows'&&$originalEncrypted){
  $keyBackup=$work.'/application-key.dpapi';
  $protectScript='$ErrorActionPreference="Stop"; Add-Type -AssemblyName System.Security; $keyBytes=[Text.Encoding]::UTF8.GetBytes($env:CLASSROOM_RECOVERY_KEY); $cipher=[Security.Cryptography.ProtectedData]::Protect($keyBytes,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser); [IO.File]::WriteAllBytes($env:CLASSROOM_RECOVERY_KEY_FILE,$cipher); [Console]::Write("protected")';
  $unprotectScript='$ErrorActionPreference="Stop"; Add-Type -AssemblyName System.Security; $cipher=[IO.File]::ReadAllBytes($env:CLASSROOM_RECOVERY_KEY_FILE); $plain=[Security.Cryptography.ProtectedData]::Unprotect($cipher,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser); [Console]::Write([Text.Encoding]::UTF8.GetString($plain))';
  $step('backup_master_key_using_windows_current_user_dpapi',function()use($protectScript,$originalKey,$previousKeys,$keyBackup,$work){$bundle=json_encode(['key'=>$originalKey,'previous_keys'=>$previousKeys],JSON_THROW_ON_ERROR);$p=new Process(['powershell.exe','-NoProfile','-NonInteractive','-Command',$protectScript],$work,['CLASSROOM_RECOVERY_KEY'=>$bundle,'CLASSROOM_RECOVERY_KEY_FILE'=>$keyBackup]);$p->setTimeout(30);$p->run();if(!$p->isSuccessful()||$p->getOutput()!=='protected')throw new RuntimeException('master_key_private_backup_failed');});
  $step('wrong_key_cannot_decrypt_original_history',function()use($resetKey,$version){$resetKey('base64:'.base64_encode(random_bytes(32)));try{StorageRegistry::configuration($version);}catch(Throwable){return;}throw new RuntimeException('wrong_master_key_unexpectedly_accepted');});
  $step('restore_private_key_and_decrypt_original_encrypted_rows',function()use($unprotectScript,$keyBackup,$work,$resetKey,$version){$p=new Process(['powershell.exe','-NoProfile','-NonInteractive','-Command',$unprotectScript],$work,['CLASSROOM_RECOVERY_KEY_FILE'=>$keyBackup]);$p->setTimeout(30);$p->run();if(!$p->isSuccessful())throw new RuntimeException('master_key_private_restore_failed');$restored=json_decode($p->getOutput(),true,16,JSON_THROW_ON_ERROR);$resetKey($restored['key'],$restored['previous_keys']);StorageRegistry::configuration($version);foreach(DB::table('settings_versions')->get()as$row)App\Settings\Settings::decode($row);});
  $report['master_key']=['status'=>'passed','method'=>'same-host Windows CurrentUser DPAPI protected private backup and restore','original_encrypted_oss_and_settings_decrypted'=>true,'wrong_key_rejected'=>true,'key_value_or_fingerprint_disclosed'=>false,'offsite_vault_and_different_os_host_restore'=>'unverified'];
 }else $report['master_key']=['status'=>'unverified','reason'=>$originalEncrypted?'DPAPI drill requires Windows; current secure environment injection used':'Legacy source had no original encrypted configuration; explicit registration is not original key-backup verification'];
 $lesson=DB::table('lessons')->find($lessonId);StorageRegistry::lessonVersion($lesson);
 // Only restored path references change. Source filesystem and objects are untouched.
 DB::table('audio_segments')->where('lesson_id',$lessonId)->update(['local_path'=>$work.'/intentionally-unavailable-old-node/frame.pcm']);DB::table('audio_chunks')->where('lesson_id',$lessonId)->update(['local_path'=>null]);
 $recovery=new SegmentRecovery;$expectedSamples=(int)$lesson->media_samples;$segments=[];$combined=hash_init('sha256');
 $step('real_oss_get_restore_all_frames_into_empty_new_storage',function()use($lessonId,$recovery,&$segments,$combined){foreach(DB::table('audio_segments')->where('lesson_id',$lessonId)->orderBy('start_sample')->cursor()as$s){$path=$recovery->ensure($s);if(filesize($path)!==(int)$s->sample_count*2||!hash_equals($s->sha256,hash_file('sha256',$path)))throw new RuntimeException('restored_frame_checksum_mismatch');$s->local_path=$path;$segments[]=$s;$f=fopen($path,'rb');hash_update_stream($combined,$f);fclose($f);}});
 $rawHash=hash_final($combined);$directory=RecordingPaths::archiveDirectory($lessonId);$assembler=new PcmArchiveAssembler;
 $result=$step('real_ffmpeg_local_archive_without_overwriting_old_cloud_key',function()use($assembler,$segments,$expectedSamples,$directory,$rawHash){$assembler->concatenate($segments,$expectedSamples,$directory.'/restored-input.pcm',$directory.'/manifest.ndjson');if(!hash_equals($rawHash,hash_file('sha256',$directory.'/restored-input.pcm')))throw new RuntimeException('reconstructed_pcm_checksum_mismatch');return $assembler->encode($directory.'/restored-input.pcm',$directory.'/restored-original.m4a',$expectedSamples);});
 $report['objects']=['status'=>'passed','real_oss_get'=>true,'segments_restored'=>count($segments),'chunks_referenced'=>DB::table('audio_chunks')->where('lesson_id',$lessonId)->count(),'sample_count'=>$expectedSamples,'pcm_sha256'=>$rawHash,'every_segment_hash_matches_database'=>true,'source_audio_local_files_not_used'=>true,'existing_object_keys_not_overwritten'=>true];
 $report['archive']=['status'=>'passed','upload'=>'local rebuild only; original cloud recording unchanged',...$result];
 $step('head_original_cloud_archive_without_modifying_it',function()use($lessonId,&$report,$result){$recording=DB::table('recordings')->where('lesson_id',$lessonId)->first();if(!$recording)throw new RuntimeException('source_archive_missing');$head=StorageRegistry::resolve($recording->storage_version)->head($recording->object_key);if($head['bytes']!==(int)$recording->bytes||!hash_equals($recording->sha256,$head['sha256']??''))throw new RuntimeException('original_archive_head_mismatch');$report['objects']['original_cloud_archive_head_verified']=true;$report['archive']['rebuilt_archive_sha_matches_original']=hash_equals($recording->sha256,$result['sha256']);});
 $cloudStore=StorageRegistry::resolve($version);$cloudProof=$cloudStore->configuration->objectKey('_disaster-recovery-tests/'.bin2hex(random_bytes(16)).'/proof.pcm');
 $step('real_random_private_put_head_get_then_cleanup',function()use($cloudStore,$cloudProof,$segments){$body=file_get_contents($segments[0]->local_path);$hash=hash('sha256',$body);$cloudStore->put($cloudProof,$body,metadata:['sha256'=>$hash]);$h=$cloudStore->head($cloudProof);if($h['bytes']!==strlen($body)||!hash_equals($hash,$h['sha256']??'')||!hash_equals($hash,hash('sha256',$cloudStore->read($cloudProof))))throw new RuntimeException('random_restored_credentials_probe_failed');});
 $report['status']=$report['master_key']['status']==='passed'?'passed':'partial_unverified';
}catch(Throwable $e){if($source instanceof PDO&&$source->inTransaction())$source->rollBack();$report['status']='failed';$report['error_type']=get_class($e);if(preg_match('/^[a-z0-9_]+$/D',$e->getMessage()))$report['error_code']=$e->getMessage();}
finally{
 if($cloudProof&&$cloudStore){try{$cloudStore->delete($cloudProof);$report['random_object_cleanup']='passed_only_one_precomputed_new_key';}catch(Throwable){$report['random_object_cleanup']='failed';$report['status']='failed';}}
 $resetKey($originalKey,$previousKeys);app()->useStoragePath($originalStorage);
 $report['finished_at']=gmdate(DATE_ATOM);$report['duration_ms']=(int)((hrtime(true)-$started)/1000000);$report['real_model_requests_during_drill']=0;$report['unverified']=['offsite/full machine-loss master-key recovery','Linux/Docker restore','real microphone'];
 file_put_contents($reportPath,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
 echo json_encode(['status'=>$report['status'],'report_path'=>$reportPath,'existing_oss_objects_modified'=>false,'source_database_modified'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
}
exit($report['status']==='passed'?0:($report['status']==='partial_unverified'?2:1));

