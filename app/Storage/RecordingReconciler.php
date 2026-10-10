<?php
declare(strict_types=1);
namespace App\Storage;

use App\Jobs\{UploadChunk,UploadSegment,ArchiveLesson};
use Carbon\Carbon;
use Illuminate\Support\Facades\{Cache,DB};

final class RecordingReconciler
{
 public function scan(bool $apply=false,int $limit=100,bool $retryFailed=true):array
 {
  $limit=max(1,min(1000,$limit));
  $result=['mode'=>$apply?'apply':'dry_run','limit'=>$limit,'retry_failed'=>$retryFailed,'open_chunks'=>[],'uploads'=>[],'legacy_uploads'=>[],'archives'=>[]];
  // Shared by scheduled and manual scans. A second scanner skips instead of
  // issuing another batch while the first holds the bounded cache lock.
  $lock=Cache::lock('recording-reconciliation-scan',300);
  if(!$lock->get())return [...$result,'skipped'=>'scan_already_running'];
  try{
   $open=DB::table('audio_chunks as c')->join('lessons as l','l.id','=','c.lesson_id')->whereNull('l.purge_status')->where('c.storage_state','open')->where(fn($q)=>$q->where('c.created_at','<=',now()->subSeconds(10))->orWhere('l.status','<>','live')->orWhereColumn('c.epoch','<>','l.recorder_epoch'))->orderBy('c.id')->limit($limit)->select('c.*')->get();
   foreach($open as$c){
    $eligible=$apply?DB::transaction(function()use($c){$l=$this->lesson((int)$c->lesson_id);$fresh=DB::table('audio_chunks')->where('id',$c->id)->lockForUpdate()->first();if(!$l||!$fresh||!$this->openEligible($fresh,$l))return false;ChunkPlanner::seal((int)$fresh->id);UploadChunk::dispatch((int)$fresh->id)->afterCommit();return true;}):true;
    if($eligible)$result['open_chunks'][]=(int)$c->id;
   }
   $remaining=$this->remaining($result,$limit);
   if($remaining>0){
    $uploads=$this->uploads('audio_chunks',$retryFailed)->orderBy('id')->limit($remaining)->get();
    foreach($uploads as$c){
     $eligible=$apply?DB::transaction(function()use($c,$retryFailed){$l=$this->lesson((int)$c->lesson_id);$fresh=DB::table('audio_chunks')->where('id',$c->id)->lockForUpdate()->first();if(!$l||!$fresh||!$this->uploadEligible($fresh,$retryFailed))return false;DB::table('audio_chunks')->where('id',$fresh->id)->update(['storage_state'=>'pending','updated_at'=>now()]);UploadChunk::dispatch((int)$fresh->id)->afterCommit();return true;}):true;
     if($eligible)$result['uploads'][]=(int)$c->id;
    }
   }
   $remaining=$this->remaining($result,$limit);
   if($remaining>0){
    $legacy=$this->uploads('audio_segments',$retryFailed)->whereNull('chunk_id')->orderBy('id')->limit($remaining)->get();
    foreach($legacy as$s){
     $eligible=$apply?DB::transaction(function()use($s,$retryFailed){$l=$this->lesson((int)$s->lesson_id);$fresh=DB::table('audio_segments')->where('id',$s->id)->lockForUpdate()->first();if(!$l||!$fresh||$fresh->chunk_id!==null||!$this->uploadEligible($fresh,$retryFailed))return false;DB::table('audio_segments')->where('id',$fresh->id)->update(['storage_state'=>'pending','updated_at'=>now()]);UploadSegment::dispatch((int)$fresh->id)->afterCommit();return true;}):true;
     if($eligible)$result['legacy_uploads'][]=(int)$s->id;
    }
   }
   $remaining=$this->remaining($result,$limit);
   if($remaining>0){
    $archives=DB::table('lessons')->whereNull('purge_status')->where('status','ended')->where('media_samples','>',0)->where(fn($q)=>$q->whereIn('archive_status',$retryFailed?['pending','failed']:['pending'])->where('updated_at','<=',now()->subSeconds(30))->orWhere('archive_status','processing')->where('updated_at','<=',now()->subSeconds(7300)))->orderBy('id')->limit($remaining)->get();
    foreach($archives as$l){
     $eligible=$apply?DB::transaction(function()use($l,$retryFailed){$fresh=$this->lesson((int)$l->id);if(!$fresh||!$this->archiveEligible($fresh,$retryFailed))return false;DB::table('lessons')->where('id',$fresh->id)->whereNull('purge_status')->update(['archive_status'=>'pending','updated_at'=>now()]);ArchiveLesson::dispatch((int)$fresh->id)->afterCommit();return true;}):true;
     if($eligible)$result['archives'][]=(int)$l->id;
    }
   }
   return $result;
  }finally{$lock->release();}
 }
 private function uploads(string $table,bool $retryFailed):\Illuminate\Database\Query\Builder
 {
  return DB::table($table)->whereExists(fn($q)=>$q->selectRaw('1')->from('lessons')->whereColumn('lessons.id',$table.'.lesson_id')->whereNull('lessons.purge_status'))->where(fn($q)=>$q->whereIn('storage_state',$retryFailed?['pending','failed']:['pending'])->where('updated_at','<=',now()->subSeconds(30))->orWhere('storage_state','uploading')->where('updated_at','<=',now()->subSeconds(150)));
 }
 private function lesson(int $id):?object{$l=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();return $l&&$l->purge_status===null?$l:null;}
 private function stale(string $at,int $seconds):bool{return Carbon::parse($at)->lte(now()->subSeconds($seconds));}
 private function openEligible(object $c,object $l):bool{return $c->storage_state==='open'&&($this->stale($c->created_at,10)||$l->status!=='live'||(int)$c->epoch!==(int)$l->recorder_epoch);}
 private function uploadEligible(object $s,bool $retryFailed):bool{return(in_array($s->storage_state,$retryFailed?['pending','failed']:['pending'],true)&&$this->stale($s->updated_at,30))||($s->storage_state==='uploading'&&$this->stale($s->updated_at,150));}
 private function archiveEligible(object $l,bool $retryFailed):bool{return$l->status==='ended'&&(int)$l->media_samples>0&&((in_array($l->archive_status,$retryFailed?['pending','failed']:['pending'],true)&&$this->stale($l->updated_at,30))||($l->archive_status==='processing'&&$this->stale($l->updated_at,7300)));}
 private function remaining(array $result,int $limit):int{return$limit-count($result['open_chunks'])-count($result['uploads'])-count($result['legacy_uploads'])-count($result['archives']);}
}
