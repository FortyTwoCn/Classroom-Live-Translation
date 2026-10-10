<?php
namespace Tests;
abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase {
 protected function setUp():void {parent::setUp();if(\Illuminate\Support\Facades\DB::transactionLevel()>0){\Illuminate\Support\Facades\DB::table('settings_versions')->where('active',true)->update(['active'=>false]);}}
 public function createApplication() { $app=require __DIR__.'/../bootstrap/app.php'; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $app['config']->set('app.key','base64:'.base64_encode(str_repeat('t',32))); $app['config']->set('hashing.bcrypt.rounds',4); return $app; }
 public function withSession(array $data) {if(isset($data['uid'])&&!array_key_exists('auth_version',$data))$data['auth_version']=(int)\Illuminate\Support\Facades\DB::table('users')->where('id',$data['uid'])->value('auth_version');return parent::withSession($data);}
}
