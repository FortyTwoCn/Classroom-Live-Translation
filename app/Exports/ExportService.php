<?php
declare(strict_types=1);

namespace App\Exports;

use App\Domain\{Access,Failure};
use App\Jobs\BuildSubtitleExport;
use Illuminate\Support\Facades\{DB,Log};
use Illuminate\Support\Str;

final class ExportService
{
    public function create(int $lessonId,?object $user,array $options,?string $sessionId=null):array
    {
        $user=$this->member($user);
        $hash=hash('sha256',json_encode([$lessonId,$options['format'],$options['layout'],$options['text_version']],JSON_THROW_ON_ERROR));
        $id=DB::transaction(function()use($lessonId,$user,$options,$hash,$sessionId){
            // Shared with model persistence and revisions: snapshot excludes uncommitted changes.
            DB::table('lessons')->where('id',$lessonId)->lockForUpdate()->first();
            $lesson=Access::lesson($lessonId,$user,$sessionId);
            DB::table('users')->where('id',$user->id)->lockForUpdate()->first();
            $user=$this->member($user);
            $prior=DB::table('subtitle_exports')->where('owner_id',$user->id)->where('idempotency_key',$options['idempotency_key'])->first();
            if($prior){if($prior->request_hash!==$hash)throw new Failure('idempotency_conflict',409);return $prior->id;}
            if(DB::table('subtitle_exports')->where('owner_id',$user->id)->whereIn('status',['queued','processing'])->where('expires_at','>',now())->count()>=2)throw new Failure('export_concurrency_limit',429);
            if(DB::table('subtitle_exports')->where('owner_id',$user->id)->where('created_at','>',now()->subMinute())->count()>=5)throw new Failure('export_rate_limit',429);
            $base=DB::table('subtitle_units')->where('lesson_id',$lessonId)->whereNull('deleted_at');
            $textExpression=$options['text_version']==='original'?'original_text':'COALESCE(current_text,original_text)';
            $selected=(clone $base)->where('status','completed')->whereRaw("btrim($textExpression,chr(9)||chr(10)||chr(11)||chr(12)||chr(13)||' ')<>''");
            if($options['layout']!=='bilingual')$selected->where('kind',$options['layout']);
            if(!$selected->exists())throw new Failure('export_no_content',409);
            $sourceLanguages=(clone $base)->where('status','completed')->where('kind','source')->whereNotNull('language')->distinct()->pluck('language')->all();
            $metadata=['course_name'=>$lesson->course_name,'lesson_name'=>$lesson->name,'lesson_created_at'=>$lesson->created_at,
                'source_language'=>count($sourceLanguages)===1?$sourceLanguages[0]:'unknown','target_language'=>$lesson->target_language,
                'exported_at'=>now()->toIso8601String(),'media_samples'=>(int)$lesson->media_samples,
                'skipped_incomplete'=>(clone $base)->where('status','!=','completed')->count(),
                'skipped_empty'=>(clone $base)->where('status','completed')->whereRaw("btrim($textExpression,chr(9)||chr(10)||chr(11)||chr(12)||chr(13)||' ')=''")->count()];
            $id=(string)Str::uuid();
            DB::table('subtitle_exports')->insert(['id'=>$id,'lesson_id'=>$lessonId,'owner_id'=>$user->id,'idempotency_key'=>$options['idempotency_key'],
                'request_hash'=>$hash,'status'=>'queued','format'=>$options['format'],'layout'=>$options['layout'],'text_version'=>$options['text_version'],
                'cutoff_seq'=>(int)(DB::table('lesson_events')->where('lesson_id',$lessonId)->max('seq')??0),'metadata'=>json_encode($metadata,JSON_UNESCAPED_UNICODE),
                'expires_at'=>now()->addHours(24)]);
            DB::insert(<<<'SQL'
INSERT INTO subtitle_export_rows(export_id,ordinal,payload)
SELECT ?,row_number() OVER(ORDER BY COALESCE(start_sample,9223372036854775807),id),
jsonb_build_object('id',id,'kind',kind,'model_session_id',model_session_id,'item_id',item_id,'source_item_id',source_item_id,'mapping',mapping,'content_index',content_index,'language',language,'start_sample',start_sample,'end_sample',end_sample,'timing_precision',timing_precision,'version',version,'text',CASE WHEN ?='original' THEN original_text ELSE COALESCE(current_text,original_text) END)
FROM subtitle_units WHERE lesson_id=? AND status='completed' AND deleted_at IS NULL AND btrim(CASE WHEN ?='original' THEN original_text ELSE COALESCE(current_text,original_text) END,chr(9)||chr(10)||chr(11)||chr(12)||chr(13)||' ')<>''
SQL,[$id,$options['text_version'],$lessonId,$options['text_version']]);
            $capacity=DB::table('subtitle_export_rows')->where('export_id',$id)->selectRaw("count(*) AS units,COALESCE(sum(octet_length(payload->>'text')),0) AS bytes")->first();
            if((int)$capacity->units>20000||(int)$capacity->bytes>8388608)throw new Failure('export_snapshot_limit',413,['max_units'=>20000,'max_text_bytes'=>8388608]);
            DB::table('subtitle_exports')->where('id',$id)->update(['snapshot_count'=>(int)$capacity->units]);
            BuildSubtitleExport::dispatch($id)->afterCommit();
            return $id;
        });
        return $this->status($id,$user,$sessionId);
    }

    public function status(string $id,?object $user,?string $sessionId=null):array
    {
        $task=$this->authorized($id,$user,$sessionId);
        if(strtotime($task->expires_at)<=time()){$this->expire($task);$task->status='expired';}
        $report=json_decode($task->report,true,flags:JSON_THROW_ON_ERROR);
        return ['id'=>$task->id,'lesson_id'=>(int)$task->lesson_id,'status'=>$task->status,'format'=>$task->format,'layout'=>$task->layout,
            'text_version'=>$task->text_version,'cutoff_seq'=>(int)$task->cutoff_seq,'snapshot_count'=>(int)$task->snapshot_count,
            'created_at'=>$task->created_at,'expires_at'=>$task->expires_at,'failure_code'=>$task->failure_code,
            'counts'=>$report['counts']??['snapshot_units'=>(int)$task->snapshot_count], 'warnings'=>$report['warnings']??[],
            'filename'=>$task->filename,'download_url'=>$task->status==='ready'?url('/api/exports/'.$task->id.'/file'):null];
    }

    public function retry(string $id,?object $user,?string $sessionId=null):array
    {
        DB::transaction(function()use($id,$user,$sessionId){
            $task=$this->authorized($id,$user,$sessionId,true);
            if(strtotime($task->expires_at)<=time())throw new Failure('export_expired',410);
            if($task->status!=='failed')throw new Failure('export_not_failed',409);
            $report=json_decode($task->report,true,flags:JSON_THROW_ON_ERROR);$retries=(int)($report['retry_requests']??0);
            if($retries>=3||time()-(int)($report['last_retry_at']??0)<10)throw new Failure('export_retry_limit',429,['max_retries'=>3,'cooldown_seconds'=>10]);
            DB::table('users')->where('id',$task->owner_id)->lockForUpdate()->first();
            $this->member($user);
            if(DB::table('subtitle_exports')->where('owner_id',$task->owner_id)->whereIn('status',['queued','processing'])->where('expires_at','>',now())->count()>=2)throw new Failure('export_concurrency_limit',429);
            $report['retry_requests']=$retries+1;$report['last_retry_at']=time();
            DB::table('subtitle_exports')->where('id',$id)->update(['status'=>'queued','failure_code'=>null,'report'=>json_encode($report),'updated_at'=>now()]);
            BuildSubtitleExport::dispatch($id)->afterCommit();
        });
        return $this->status($id,$user,$sessionId);
    }

    public function file(string $id,?object $user,?string $sessionId=null):array
    {
        $task=$this->authorized($id,$user,$sessionId);
        if(strtotime($task->expires_at)<=time()){$this->expire($task);throw new Failure('export_expired',410);}
        if($task->status!=='ready')throw new Failure('export_not_ready',409);
        $path=$this->path($task);
        if($task->file_path!==$path||!is_file($path))throw new Failure('export_file_unavailable',404);
        return ['path'=>$path,'filename'=>$task->filename,'mime'=>$task->mime];
    }

    public function build(string $id,SubtitleFormatter $formatter):void
    {
        $task=DB::transaction(function()use($id){$task=DB::table('subtitle_exports')->where('id',$id)->lockForUpdate()->first();
            if(!$task||in_array($task->status,['ready','expired'],true))return null;
            if(strtotime($task->expires_at)<=time()){$this->expire($task);return null;}
            try{$this->authorized($id,Access::user((int)$task->owner_id));}catch(Failure){DB::table('subtitle_exports')->where('id',$id)->update(['status'=>'failed','failure_code'=>'export_permission_revoked','updated_at'=>now()]);return null;}
            DB::table('subtitle_exports')->where('id',$id)->update(['status'=>'processing','failure_code'=>null,'updated_at'=>now()]);return $task;});
        if(!$task)return;
        try{
            $metadata=json_decode($task->metadata,true,flags:JSON_THROW_ON_ERROR);
            $rows=DB::table('subtitle_export_rows')->where('export_id',$id)->orderBy('ordinal')->cursor()->map(fn($row)=>json_decode($row->payload,true,flags:JSON_THROW_ON_ERROR));
            $result=$formatter->render($rows,['format'=>$task->format,'layout'=>$task->layout,'text_version'=>$task->text_version],$metadata);
            $previousReport=json_decode($task->report,true,flags:JSON_THROW_ON_ERROR);
            $report=json_encode(['counts'=>$result['counts'],'warnings'=>$result['warnings'],'retry_requests'=>(int)($previousReport['retry_requests']??0),'last_retry_at'=>(int)($previousReport['last_retry_at']??0)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($result['counts']['exported_segments']===0){DB::transaction(function()use($id,$report){$current=DB::table('subtitle_exports')->where('id',$id)->lockForUpdate()->first();if(!$current||$current->status==='expired')return;if(strtotime($current->expires_at)<=time()){$this->expire($current);return;}DB::table('subtitle_exports')->where('id',$id)->update(['status'=>'failed','failure_code'=>'export_empty_timed','report'=>$report,'updated_at'=>now()]);});return;}
            $path=$this->path($task);$directory=dirname($path);
            if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new \RuntimeException('export_file_failed');
            $stream=fopen($path.'.tmp','wb');if($stream===false)throw new \RuntimeException('export_file_failed');
            try{$bytes=$result['contents'];$offset=0;while($offset<strlen($bytes)){$written=fwrite($stream,substr($bytes,$offset));if($written===false||$written===0)throw new \RuntimeException('export_file_failed');$offset+=$written;}if(!fflush($stream)||!fsync($stream))throw new \RuntimeException('export_file_failed');}finally{fclose($stream);}
            if(!rename($path.'.tmp',$path))throw new \RuntimeException('export_file_failed');
            chmod($path,0600);
            DB::transaction(function()use($task,$id,$path,$report,$metadata){
                $current=DB::table('subtitle_exports')->where('id',$id)->lockForUpdate()->first();
                if($current->status==='expired'||strtotime($current->expires_at)<=time()){$this->expire($current);return;}
                try{$this->authorized($id,Access::user((int)$task->owner_id));}catch(Failure){if(is_file($path))unlink($path);DB::table('subtitle_exports')->where('id',$id)->update(['status'=>'failed','failure_code'=>'export_permission_revoked','updated_at'=>now()]);return;}
                $filename=$this->filename($metadata,$task);
                DB::table('subtitle_exports')->where('id',$id)->update(['status'=>'ready','file_path'=>$path,'filename'=>$filename,'mime'=>$this->mime($task->format),'report'=>$report,'updated_at'=>now()]);
            });
        }catch(\Throwable $error){
            DB::table('subtitle_exports')->where('id',$id)->where('status','!=','expired')->update(['status'=>'failed','failure_code'=>'export_file_failed','updated_at'=>now()]);
            Log::warning('subtitle.export_failed',['export_id'=>$id,'error_type'=>get_class($error)]);
            // Queue diagnostics never retain subtitle text or private paths.
            throw new \RuntimeException('export_file_failed');
        }
    }

    public function expireIfDue(string $id):bool
    {
        if(!Str::isUuid($id))return false;
        return DB::transaction(function()use($id){$task=DB::table('subtitle_exports')->where('id',$id)->lockForUpdate()->first();if(!$task||strtotime($task->expires_at)>time())return false;$this->expire($task);return true;});
    }

    public function cleanup():int
    {
        $count=0;
        foreach(DB::table('subtitle_exports')->where('expires_at','<=',now())->where('status','!=','expired')->cursor() as $task){$this->expire($task);++$count;}
        DB::table('share_grants')->whereNotIn('session_id',DB::table('sessions')->select('id'))->where('created_at','<',now()->subDay())->delete();
        return $count;
    }

    private function authorized(string $id,?object $user,?string $sessionId=null,bool $lock=false):object
    {
        $user=$this->member($user);
        if(!Str::isUuid($id))throw new Failure('export_unavailable',404);
        $query=DB::table('subtitle_exports')->where('id',$id)->where('owner_id',$user->id);if($lock)$query->lockForUpdate();
        $task=$query->first();if(!$task)throw new Failure('export_unavailable',404);
        Access::lesson((int)$task->lesson_id,$user,$sessionId);return $task;
    }
    private function member(?object $user):object { $fresh=$user?Access::user((int)$user->id):null;Access::member($fresh);if(isset($user->auth_version)&&(int)$fresh->auth_version!==(int)$user->auth_version)throw new Failure('session_revoked',401);return $fresh; }
    private function path(object $task):string
    {
        if(!Str::isUuid($task->id)||!in_array($task->format,['txt','md','srt','vtt'],true))throw new \RuntimeException('export_path_invalid');
        $base=storage_path('app/private/subtitle-exports');$path=$base.'/'.$task->id.'/subtitles.'.$task->format;
        // Parent symlinks cannot route exported files outside the private storage tree.
        for($directory=dirname($path);strlen($directory)>=strlen(storage_path());$directory=dirname($directory)){if(is_link($directory))throw new \RuntimeException('export_path_invalid');if($directory===storage_path())break;}
        return $path;
    }
    private function expire(object $task):void
    {
        $path=$this->path($task);foreach([$path,$path.'.tmp'] as $file)if(is_file($file)&&!is_link($file))unlink($file);
        DB::transaction(function()use($task){DB::table('subtitle_exports')->where('id',$task->id)->update(['status'=>'expired','file_path'=>null,'updated_at'=>now()]);DB::table('subtitle_export_rows')->where('export_id',$task->id)->delete();});
    }
    private function filename(array $metadata,object $task):string
    {
        $name=preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u','_', $metadata['course_name'].'_'.$metadata['lesson_name']);
        $name=trim(mb_substr($name,0,100),' ._');return ($name?:'subtitles').'_'.substr($metadata['exported_at'],0,10).'_'.$task->layout.'_'.$task->text_version.'.'.$task->format;
    }
    private function mime(string $format):string { return match($format){'txt'=>'text/plain; charset=UTF-8','md'=>'text/markdown; charset=UTF-8','srt'=>'application/x-subrip','vtt'=>'text/vtt; charset=UTF-8'}; }
}
