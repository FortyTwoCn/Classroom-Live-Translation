$ErrorActionPreference='Stop'
$project=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$workspace=Split-Path -Parent (Split-Path -Parent $project)
$php=Join-Path $workspace 'work/runtime/php/php.exe'
$pg=Join-Path $workspace 'work/runtime/pg/pgsql/bin/psql.exe'
$node=(Get-Command node).Source
foreach($port in @(20080,20081,20082)){if(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue){throw "Recovery test port $port occupied; no process was stopped."}}
$suffix=[guid]::NewGuid().ToString('N').Substring(0,12)
$run=Join-Path $workspace ('work/recovery-'+$suffix)
$storage=Join-Path $run 'storage'
foreach($folder in @('app/recordings','framework/cache/data','framework/sessions','framework/views','logs')){New-Item -ItemType Directory -Force -Path (Join-Path $storage $folder) | Out-Null}
$env:APP_ENV='testing';$env:APP_DEBUG='false';$env:RECOVERY_TEST_PROFILE='1';$env:LARAVEL_STORAGE_PATH=$storage
$env:DB_DATABASE='classroom_integration_'+$suffix
$env:PGPASSWORD=[IO.File]::ReadAllText((Join-Path $workspace 'work/runtime/pg-password.txt')).Trim()
& $pg -h 127.0.0.1 -p 55432 -U classroom_dev -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE $($env:DB_DATABASE) OWNER classroom_dev"
if($LASTEXITCODE -ne 0){throw 'Isolated recovery DB creation failed'}
Remove-Item Env:PGPASSWORD
$env:APP_URL='http://localhost:20080';$env:SESSION_SECURE_COOKIE='false';$env:CACHE_STORE='database';$env:SESSION_DRIVER='database';$env:QUEUE_CONNECTION='database'
$env:WS_LISTEN='websocket://127.0.0.1:20081';$env:WS_PUBLIC_URL='ws://localhost:20081';$env:WS_ALLOWED_ORIGINS='http://localhost:20080';$env:MOCK_QWEN_LISTEN='websocket://127.0.0.1:20082'
$env:QWEN_API_KEY='explicit-recovery-mock-key';$env:QWEN_WORKSPACE_ID='fixture';$env:QWEN_REGION='cn-beijing';$env:QWEN_ALLOW_TEST_ENDPOINT='true';$env:QWEN_WS_ENDPOINT='ws://127.0.0.1:20082/api-ws/v1/realtime'
$env:OSS_ACCESS_KEY_ID='explicit-recovery-mock-key';$env:OSS_ACCESS_KEY_SECRET='explicit-recovery-mock-secret';$env:OSS_SESSION_TOKEN='';$env:OSS_ENDPOINT='https://s3.oss-cn-hangzhou.aliyuncs.com';$env:OSS_REGION='cn-hangzhou';$env:OSS_BUCKET='explicit-recovery-fixture';$env:OSS_PREFIX='recovery-fixture'
$env:TEST_COURSE_LABEL='EXPLICIT RECOVERY MOCK / actual process termination'
Push-Location $project
$children=[Collections.Generic.List[object]]::new()
function Start-RecoveryChild([string]$name,[string[]]$arguments){$process=Start-Process -FilePath $php -ArgumentList $arguments -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $run ($name+'.log')) -RedirectStandardError (Join-Path $run ($name+'-error.log'));$entry=@{name=$name;pid=$process.Id;started=$process.StartTime.ToUniversalTime().ToString('o')};$children.Add($entry);return $entry}
function Stop-RecoveryChild($entry){$process=Get-Process -Id $entry.pid -ErrorAction SilentlyContinue;if($process -and $process.StartTime.ToUniversalTime().ToString('o') -eq $entry.started){Stop-Process -Id $process.Id -ErrorAction Stop}}
try{
 & $php artisan migrate --force
 if($LASTEXITCODE -ne 0){throw 'Recovery migrations failed'}
 $fixturePath=Join-Path $run 'fixture.json'
 & $php tests/fixtures/seed.php | Set-Content -LiteralPath $fixturePath -Encoding utf8
 if($LASTEXITCODE -ne 0){throw 'Recovery fixture failed'}
 $fixture=Get-Content -LiteralPath $fixturePath -Raw | ConvertFrom-Json
 Start-RecoveryChild 'http' @('-S','127.0.0.1:20080','-t','public','public/router.php') | Out-Null
 Start-RecoveryChild 'mock' @('tests/fixtures/mock-qwen.php','start') | Out-Null
 $gateway=Start-RecoveryChild 'gateway' @('bin/gateway.php','start')
 $deadline=[DateTime]::UtcNow.AddSeconds(20)
 do{Start-Sleep -Milliseconds 200;if([DateTime]::UtcNow -gt $deadline){throw 'Recovery service startup timeout'}}until(@(Get-NetTCPConnection -State Listen -LocalPort 20080,20081,20082 -ErrorAction SilentlyContinue).Count -ge 3)
 $report=Join-Path $project 'reports/process-recovery-simulated.json'
 if(Test-Path -LiteralPath $report){$previousReport=Get-Content -LiteralPath $report -Raw|ConvertFrom-Json;if($previousReport.status -eq 'failed'){Copy-Item -LiteralPath $report -Destination (Join-Path $project ('reports/process-recovery-failed-'+$suffix+'.json'))}}
 $runner=Start-Process -FilePath $node -ArgumentList @('tests/e2e/recovery.mjs',('"'+$fixturePath+'"'),('"'+$run+'"'),('"'+$report+'"')) -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $run 'runner.log') -RedirectStandardError (Join-Path $run 'runner-error.log')
 $children.Add(@{name='runner';pid=$runner.Id;started=$runner.StartTime.ToUniversalTime().ToString('o')})
 $deadline=[DateTime]::UtcNow.AddSeconds(120)
 while(-not $runner.HasExited){
  $actionFile=Join-Path $run 'action.json'
  if(Test-Path -LiteralPath $actionFile){$name=(Get-Content -LiteralPath $actionFile -Raw | ConvertFrom-Json).action
   if(-not(Test-Path -LiteralPath (Join-Path $run ($name+'.done.json')))){
    if($name -eq 'restart'){
     $oldGatewayPid=$gateway.pid
     Stop-RecoveryChild $gateway
     $gateway=Start-RecoveryChild 'gateway-restarted' @('bin/gateway.php','start')
     $restartDeadline=[DateTime]::UtcNow.AddSeconds(15)
     do{Start-Sleep -Milliseconds 100;if([DateTime]::UtcNow -gt $restartDeadline){throw 'Restarted gateway listening deadline exceeded'}}until(Get-NetTCPConnection -State Listen -LocalPort 20081 -ErrorAction SilentlyContinue)
     $response=@{status='completed';old_gateway_pid=$oldGatewayPid;new_gateway_pid=$gateway.pid;forced_process_termination=$true}
    }
    elseif($name -in @('disable','enable','readback')){$data=& $php tests/fixtures/recovery-state.php $name $fixture.lesson_id;if($LASTEXITCODE -ne 0){throw 'Recovery state helper failed'};$response=@{status='completed';data=($data | ConvertFrom-Json)}}
    else{throw 'Unexpected recovery action'}
    $response | ConvertTo-Json -Depth 10 -Compress | Set-Content -LiteralPath (Join-Path $run ($name+'.done.json')) -Encoding utf8
   }
  }
  if([DateTime]::UtcNow -gt $deadline){throw 'Recovery test bounded deadline exceeded'}
  Start-Sleep -Milliseconds 200;$runner.Refresh()
 }
 $runner.WaitForExit()
 if($runner.ExitCode -ne 0){Get-Content -LiteralPath (Join-Path $run 'runner-error.log');Get-Content -LiteralPath (Join-Path $run 'runner.log');throw 'Process recovery test failed; report and isolated DB preserved'}
 Get-Content -LiteralPath $report
}finally{foreach($entry in $children){Stop-RecoveryChild $entry}Pop-Location}
