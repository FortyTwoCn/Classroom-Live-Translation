<?php
declare(strict_types=1);
require __DIR__.'/../../bin/bootstrap.php';
// Load fixtures are deliberately separate from production providers and storage.
if(!app()->environment('testing')||env('CLASSROOM_LOAD_PROFILE')!=='1'
 ||!preg_match('/^classroom_load_[a-f0-9]{12}$/D',(string)config('database.connections.pgsql.database'))
 ||config('classroom.qwen.endpoint')!=='ws://127.0.0.1:28082/api-ws/v1/realtime'
 ||config('classroom.qwen.api_key')!=='explicit-load-simulation-key'
 ||config('classroom.oss.key')!=='explicit-load-simulation-key'
 ||!str_contains(str_replace('\\','/',storage_path()),'/work/classroom-load-'))
 throw new RuntimeException('isolated_load_profile_required');
