<?php
declare(strict_types=1);
namespace App\Jobs;
use App\Storage\RecordingReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReconcileRecordings implements ShouldQueue
{
 use Queueable;
 public int $tries=2;
 public int $timeout=120;
 public function __construct(){$this->onQueue('maintenance');}
 public function handle(RecordingReconciler $reconciler):void{$reconciler->scan(apply:true,limit:100,retryFailed:false);}
}
