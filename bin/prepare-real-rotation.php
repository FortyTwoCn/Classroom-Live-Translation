#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use App\Domain\Access;
use App\Settings\Settings;
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Support\Str;
if(!app()->environment('testing')||!preg_match('/^classroom_integration_[a-f0-9]+$/D',DB::connection()->getDatabaseName()))throw new RuntimeException('Dedicated integration database required.');
$suffix=bin2hex(random_bytes(6));$student='rotation-'.$suffix;
$uid=DB::table('users')->insertGetId(['student_id'=>$student,'name'=>'Synthetic rotation recorder','role'=>'user','password'=>Hash::make('Fixture-password-2026'),'must_change_password'=>false]);
$admin=DB::table('users')->insertGetId(['student_id'=>'rotation-admin-'.$suffix,'name'=>'Synthetic rotation fixture administrator','role'=>'admin','password'=>'fixture-unused','must_change_password'=>false]);
Settings::save('policies',['rotation_soft_seconds'=>30,'rotation_hard_seconds'=>40],Settings::version('policies'),Access::user($admin));
$course=DB::table('courses')->insertGetId(['name'=>'REAL ROTATION QWEN + OSS / SYNTHETIC SPEECH','target_language'=>'zh','guest_access'=>'public']);
$lesson=DB::table('lessons')->insertGetId(['course_id'=>$course,'creator_id'=>$uid,'recorder_user_id'=>$uid,'name'=>'Synthetic audio model rotation fixture','target_language'=>'zh','idempotency_key'=>(string)Str::uuid()]);
echo json_encode(['student_id'=>$student,'lesson_id'=>$lesson,'rotation_soft_seconds'=>30,'rotation_hard_seconds'=>40,'policy_is_application_test_setting'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
