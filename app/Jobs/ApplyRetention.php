<?php
namespace App\Jobs;
use App\Settings\Settings;use Illuminate\Contracts\Queue\ShouldQueue;use Illuminate\Foundation\Queue\Queueable;use Illuminate\Support\Facades\DB;
final class ApplyRetention implements ShouldQueue {
 use Queueable; public int $tries=2;public int $timeout=300;
 public function __construct(){$this->onQueue('maintenance');}
 public function handle():void {
  $p=Settings::policies();if(!$p['auto_purge']||$p['retention_days']<1)return;
  foreach(DB::table('lessons')->where('status','ended')->where('ended_at','<',now()->subDays($p['retention_days']))->whereNull('purge_status')->where('archive_status','<>','processing')->orderBy('id')->limit(100)->pluck('id')as$id){DB::transaction(function()use($id){$l=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();if(!$l||$l->purge_status||$l->status!=='ended')return;$share=DB::table('lesson_shares')->where('lesson_id',$id)->first();if($share){DB::table('lesson_shares')->where('id',$share->id)->update(['revoked_at'=>now(),'version'=>$share->version+1]);DB::table('share_grants')->where('share_id',$share->id)->delete();}DB::table('lessons')->where('id',$id)->update(['deleted_at'=>now(),'purge_status'=>'queued']);DB::table('audit')->insert(['lesson_id'=>$id,'action'=>'retention.purge_requested']);\App\Domain\Access::lessonEvent((int)$id);PurgeLesson::dispatch((int)$id)->afterCommit();});}
 }
}
