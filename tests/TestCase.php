<?php
namespace Tests;
abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase {
 public function createApplication() { $app=require __DIR__.'/../bootstrap/app.php'; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $app['config']->set('app.key','base64:'.base64_encode(str_repeat('t',32))); $app['config']->set('hashing.bcrypt.rounds',4); return $app; }
}
