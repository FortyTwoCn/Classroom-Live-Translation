<?php
namespace App\Jobs;
use App\Settings\{Settings,ConnectionProbe};
use App\Domain\Access;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
final class TestConnection implements ShouldQueue {
 use Queueable; public int $tries=1;public int $timeout=600;
 public function __construct(public string $testId){$this->onQueue('maintenance');}
 public function middleware():array {return [(new WithoutOverlapping('connection-test:'.$this->testId))->expireAfter(650)];}
 public function handle(ConnectionProbe $probe):void {
  $t=DB::table('connection_tests')->where('id',$this->testId)->first();if(!$t||in_array($t->status,['passed','failed']))return;
  try{Access::admin(Access::user((int)$t->owner_id));$values=Settings::byVersion($t->settings_version,$t->kind);Settings::validate($t->kind,$values);DB::table('connection_tests')->where('id',$t->id)->update(['status'=>'processing','updated_at'=>now()]);$report=$t->kind==='oss'?$probe->oss($values):$probe->model($t->settings_version);DB::table('connection_tests')->where('id',$t->id)->update(['status'=>$report['status'],'report'=>json_encode($report,JSON_THROW_ON_ERROR),'updated_at'=>now()]);}
  catch(\Throwable){$this->failed(null);}
 }
 public function failed(?\Throwable $e):void {DB::table('connection_tests')->where('id',$this->testId)->update(['status'=>'failed','failure_code'=>'connection_test_failed','updated_at'=>now()]);}
}
