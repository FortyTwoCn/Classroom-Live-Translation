param(
 [ValidateRange(60,7200)][int]$DurationSeconds=60,
 [ValidateRange(1,200)][int]$Viewers=100,
 [ValidatePattern('^[a-z0-9-]{1,50}$')][string]$ReportName='load-smoke',
 [switch]$AllowLongRun,
 [int]$RotationSoftSeconds=600,
 [int]$RotationHardSeconds=720
)
$ErrorActionPreference='Stop'
if($DurationSeconds -gt 60 -and -not $AllowLongRun){throw 'Explicit -AllowLongRun is required; full tests use actual wall clock.'}
$project=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$workspace=Split-Path -Parent (Split-Path -Parent $project)
$php=Join-Path $workspace 'work/runtime/php/php.exe'
$node=(Get-Command node).Source
$scratch=Join-Path $workspace 'work'
$random=[Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(6)).ToLowerInvariant()
$runPath=Join-Path $scratch ('classroom-load-'+$random)
$storage=Join-Path $runPath 'storage'
foreach($relative in @('app','framework/cache/data','framework/sessions','framework/views','logs')){New-Item -ItemType Directory -Path (Join-Path $storage $relative) -Force | Out-Null}
foreach($port in @(28080,28081,28082)){if(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue){throw "Port $port is already in use; no processes were stopped."}}
$env:APP_ENV='testing';$env:CLASSROOM_LOAD_PROFILE='1';$env:APP_DEBUG='false'
$env:LARAVEL_STORAGE_PATH=$storage
$env:DB_CONNECTION='pgsql';$env:DB_HOST='127.0.0.1';$env:DB_PORT='55432';$env:DB_DATABASE='classroom_load_'+$random;$env:DB_USERNAME='classroom_dev'
$env:DB_PASSWORD=(Get-Content -Raw -LiteralPath (Join-Path $scratch 'runtime/pg-password.txt')).Trim()
$env:APP_URL='http://127.0.0.1:28080';$env:SESSION_SECURE_COOKIE='false';$env:SESSION_DRIVER='database';$env:CACHE_STORE='database';$env:QUEUE_CONNECTION='database'
$env:WS_LISTEN='websocket://127.0.0.1:28081';$env:WS_PUBLIC_URL='ws://127.0.0.1:28081';$env:WS_ALLOWED_ORIGINS='http://127.0.0.1:28080'
$env:QWEN_ALLOW_TEST_ENDPOINT='true';$env:QWEN_WS_ENDPOINT='ws://127.0.0.1:28082/api-ws/v1/realtime';$env:QWEN_API_KEY='explicit-load-simulation-key';$env:QWEN_WORKSPACE_ID='load-fixture';$env:QWEN_REGION='cn-beijing'
$env:OSS_ACCESS_KEY_ID='explicit-load-simulation-key';$env:OSS_ACCESS_KEY_SECRET='explicit-load-simulation-secret';$env:OSS_SESSION_TOKEN='';$env:OSS_BUCKET='explicit-load-fixture';$env:OSS_ENDPOINT='https://s3.oss-cn-hangzhou.aliyuncs.com';$env:OSS_REGION='cn-hangzhou';$env:OSS_PREFIX='load-fixture'
$env:FFMPEG_BINARY=Join-Path $scratch 'runtime/ffmpeg/ffmpeg-master-latest-win64-lgpl-shared/bin/ffmpeg.exe'
$env:FFPROBE_BINARY=Join-Path $scratch 'runtime/ffmpeg/ffmpeg-master-latest-win64-lgpl-shared/bin/ffprobe.exe'
$env:LOAD_DURATION_SECONDS=[string]$DurationSeconds;$env:LOAD_ROTATION_SOFT_SECONDS=[string]$RotationSoftSeconds;$env:LOAD_ROTATION_HARD_SECONDS=[string]$RotationHardSeconds
Push-Location $project
$children=[Collections.Generic.List[object]]::new()
$record=Join-Path $runPath 'processes.private.json'
$report=Join-Path $project ('reports/'+$ReportName+'.json')
$sourceManifest=Join-Path $project ('reports/'+$ReportName+'-source-manifest.json')
try{
 $sourceStartText=& $php 'tests/load/source-manifest.php'
 if($LASTEXITCODE -ne 0){throw 'Load source manifest failed before startup.'}
 $sourceStart=$sourceStartText | ConvertFrom-Json -AsHashtable
 @{at_start=$sourceStart;at_end=$null;exact_start_end_source_match=$null} | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $sourceManifest -Encoding utf8
 $setup=& $php 'tests/load/setup.php'
 if($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath (Join-Path $runPath 'fixture.private.json'))){throw 'Isolated load fixture setup failed.'}
 $setup | Set-Content -LiteralPath (Join-Path $runPath 'setup.json') -Encoding utf8
 foreach($spec in @(
  @{name='http';args=@('-S','127.0.0.1:28080','-t','public','public/router.php')},
  @{name='mock';args=@('tests/load/mock-qwen.php','start')},
  @{name='gateway';args=@('bin/gateway.php','start')},
  @{name='worker';args=@('tests/load/queue-worker.php','queue:work','--queue=recordings,archives','--sleep=1','--tries=6','--timeout=7200')}
 )){
  $p=Start-Process -FilePath $php -ArgumentList $spec.args -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runPath ($spec.name+'.log')) -RedirectStandardError (Join-Path $runPath ($spec.name+'-error.log'))
  $children.Add(@{name=$spec.name;pid=$p.Id;started=$p.StartTime.ToUniversalTime().ToString('o')})
 }
 $deadline=[DateTime]::UtcNow.AddSeconds(30)
 do{Start-Sleep -Milliseconds 250;$listeners=@(Get-NetTCPConnection -State Listen -LocalPort 28080,28081,28082 -ErrorAction SilentlyContinue);if([DateTime]::UtcNow -gt $deadline){throw 'Load service startup timeout; inspect private runtime logs.'}}until($listeners.Count -ge 3)
 $runner=Start-Process -FilePath $node -ArgumentList @('tests/load/viewers.mjs',('"'+(Join-Path $runPath 'fixture.private.json')+'"'),[string]$DurationSeconds,[string]$Viewers,('"'+$report+'"'),('"'+$php+'"'),('"'+$runPath+'"')) -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runPath 'viewers.log') -RedirectStandardError (Join-Path $runPath 'viewers-error.log')
 $children.Add(@{name='viewers';pid=$runner.Id;started=$runner.StartTime.ToUniversalTime().ToString('o')})
 @{database=$env:DB_DATABASE;run_path=$runPath;duration_seconds=$DurationSeconds;report_path=$report;processes=$children} | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $record -Encoding utf8
 @{status='running';database=$env:DB_DATABASE;run_path=$runPath;report_path=$report;duration_seconds=$DurationSeconds} | ConvertTo-Json -Compress | Set-Content -LiteralPath (Join-Path $scratch ($ReportName+'-run.json')) -Encoding utf8
 $previous=@{};$sample=0
 while(-not $runner.HasExited){
  $now=[DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds();$processRows=@()
  foreach($child in $children){$p=Get-Process -Id $child.pid -ErrorAction SilentlyContinue;if($p){$old=$previous[$child.pid];$cpuMs=$p.TotalProcessorTime.TotalMilliseconds;$cpuPercent=if($old){100*($cpuMs-$old.cpu)/($now-$old.at)}else{0};$processRows+=@{name=$child.name;pid=$p.Id;rss_bytes=$p.WorkingSet64;cpu_total_ms=$cpuMs;cpu_percent_one_core=$cpuPercent};$previous[$child.pid]=@{at=$now;cpu=$cpuMs}}}
  $pg=@(Get-Process -Name postgres -ErrorAction SilentlyContinue);$pgRss=($pg | Measure-Object WorkingSet64 -Sum).Sum;$pgCpu=($pg | ForEach-Object {$_.TotalProcessorTime.TotalMilliseconds} | Measure-Object -Sum).Sum
  @{at_ms=$now;processes=$processRows;postgres_shared_cluster_rss_bytes=$pgRss;postgres_shared_cluster_cpu_total_ms=$pgCpu;note='PostgreSQL cluster also serves other tests; shared RSS/CPU are not load-database-only.'} | ConvertTo-Json -Depth 6 -Compress | Add-Content -LiteralPath (Join-Path $runPath 'resource-metrics.ndjson') -Encoding utf8
  if($sample++ % 2 -eq 0){$metrics=& $php 'tests/load/metrics.php';if($LASTEXITCODE -eq 0){$metrics | Add-Content -LiteralPath (Join-Path $runPath 'database-metrics.ndjson') -Encoding utf8}}
  Start-Sleep -Seconds 5;$runner.Refresh()
 }
 $runner.WaitForExit()
 $sourceEndText=& $php 'tests/load/source-manifest.php'
 if($LASTEXITCODE -ne 0){throw 'Load source manifest failed after capture; retain benchmark report.'}
 $sourceEnd=$sourceEndText | ConvertFrom-Json -AsHashtable
 $changed=@(($sourceStart.sha256.Keys + $sourceEnd.sha256.Keys | Sort-Object -Unique) | Where-Object {$sourceStart.sha256[$_] -ne $sourceEnd.sha256[$_]})
 @{at_start=$sourceStart;at_end=$sourceEnd;exact_start_end_source_match=($changed.Count -eq 0);changed_source_files=$changed} | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $sourceManifest -Encoding utf8
 if(Test-Path -LiteralPath $report){
  $result=Get-Content -Raw -LiteralPath $report | ConvertFrom-Json -AsHashtable
  $result.code_version_evidence=@{startup_source_hash_recorded=$true;hash_manifest=(Split-Path -Leaf $sourceManifest);gateway_sha256_at_start=$sourceStart.sha256['app/Realtime/Gateway.php'];gateway_sha256_at_end=$sourceEnd.sha256['app/Realtime/Gateway.php'];exact_start_end_source_match=($changed.Count -eq 0);changed_source_files=$changed;git_head_at_start=$sourceStart.git_head;working_tree_dirty_at_start=$sourceStart.working_tree_dirty;scope='Actual start/end code snapshots; git HEAD alone does not identify uncommitted source.'}
  $result | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath $report -Encoding utf8
 }
 if($runner.ExitCode -ne 0){throw 'Load test failed; report and private runtime logs retained.'}
 Write-Output ('Load test completed: '+$report+'; isolated database retained: '+$env:DB_DATABASE)
}finally{
 foreach($child in $children){$p=Get-Process -Id $child.pid -ErrorAction SilentlyContinue;if($p -and $p.StartTime.ToUniversalTime().ToString('o') -eq $child.started){Stop-Process -Id $p.Id -ErrorAction SilentlyContinue}}
 Pop-Location
}
