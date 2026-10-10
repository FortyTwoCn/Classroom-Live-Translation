#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try{$values=App\Settings\Settings::values('oss');$version=App\Storage\StorageRegistry::register($values);echo json_encode(['status'=>'registered','storage_version'=>$version],JSON_THROW_ON_ERROR)."\n";}catch(Throwable){fwrite(STDERR,"storage_configuration_registration_failed\n");exit(1);}
