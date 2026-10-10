#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$options=getopt('',['apply','limit:']);try{$report=app(App\Storage\RecordingReconciler::class)->scan(array_key_exists('apply',$options),(int)($options['limit']??100));echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";}catch(Throwable){fwrite(STDERR,"recording_reconciliation_failed\n");exit(1);}
