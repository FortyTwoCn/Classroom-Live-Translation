<?php
require __DIR__.'/../../bin/bootstrap.php';
if(!app()->environment('testing')||!preg_match('/^classroom_(test|integration_[a-f0-9]+)$/D',config('database.connections.pgsql.database')))throw new RuntimeException('Test fixtures require isolated test/integration database');
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
$suffix=bin2hex(random_bytes(6));$student='test-'.$suffix;
$u=DB::table('users')->insertGetId(['student_id'=>$student,'name'=>'Synthetic test recorder','role'=>'user','password'=>Hash::make('Fixture-password-2026'),'must_change_password'=>false]);
$c=DB::table('courses')->insertGetId(['name'=>env('TEST_COURSE_LABEL','SIMULATED PROVIDER TEST'),'target_language'=>'zh','guest_access'=>'public']);
$l=DB::table('lessons')->insertGetId(['course_id'=>$c,'creator_id'=>$u,'recorder_user_id'=>$u,'name'=>'Synthetic audio protocol fixture','target_language'=>'zh','idempotency_key'=>(string)Illuminate\Support\Str::uuid()]);
echo json_encode(['student_id'=>$student,'lesson_id'=>$l],JSON_UNESCAPED_UNICODE);
