<?php
namespace App\Imports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
final class ConfirmImport implements ShouldQueue {
 use Queueable;
 public int $tries=3;public int $timeout=1800;
 public function __construct(public string $importId){$this->onQueue('imports');}
 public function backoff():array{return [10,60];}
 public function handle(UserImports $imports):void{$imports->execute($this->importId);}
 public function failed(?\Throwable $e):void{DB::table('user_imports')->where('id',$this->importId)->where('status','processing')->update(['status'=>'failed','updated_at'=>now()]);}
}
