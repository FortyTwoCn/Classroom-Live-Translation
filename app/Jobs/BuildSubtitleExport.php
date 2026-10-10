<?php
declare(strict_types=1);
namespace App\Jobs;
use App\Exports\{ExportService,SubtitleFormatter};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
final class BuildSubtitleExport implements ShouldQueue
{
    use Queueable;
    public int $tries=3;
    public int $timeout=300;
    public function __construct(public string $exportId){$this->onQueue('exports');}
    public function middleware():array{return [(new WithoutOverlapping('subtitle-export:'.$this->exportId))->releaseAfter(10)->expireAfter(330)];}
    public function backoff():array{return [10,30];}
    public function handle(ExportService $service,SubtitleFormatter $formatter):void{$service->build($this->exportId,$formatter);}
    public function failed(?\Throwable $error):void{DB::table('subtitle_exports')->where('id',$this->exportId)->whereNotIn('status',['ready','expired'])->update(['status'=>'failed','failure_code'=>'export_file_failed','updated_at'=>now()]);}
}
