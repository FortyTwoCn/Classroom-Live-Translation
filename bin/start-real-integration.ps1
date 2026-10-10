param([int]$HttpPort=19080,[int]$WebSocketPort=19081)
$ErrorActionPreference='Stop'
$project=Split-Path -Parent $PSScriptRoot
$workspace=Split-Path -Parent (Split-Path -Parent $project)
$scratch=Join-Path $workspace 'work'
$runtime=Join-Path $scratch 'runtime'
$php=Join-Path $runtime 'php/php.exe'
$psql=Join-Path $runtime 'pg/pgsql/bin/psql.exe'
$suffix=[guid]::NewGuid().ToString('N').Substring(0,12)
$database='classroom_integration_'+$suffix
# New local test DB; existing production/test databases are not reset.
$env:PGPASSWORD=[IO.File]::ReadAllText((Join-Path $runtime 'pg-password.txt')).Trim()
& $psql -h 127.0.0.1 -p 55432 -U classroom_dev -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE $database OWNER classroom_dev"
if($LASTEXITCODE -ne 0){throw 'Cannot create isolated integration database'}
Remove-Item Env:PGPASSWORD
$env:APP_ENV='testing'
$env:DB_DATABASE=$database
$env:APP_URL="http://localhost:$HttpPort"
$env:WS_LISTEN="websocket://127.0.0.1:$WebSocketPort"
$env:WS_PUBLIC_URL="ws://localhost:$WebSocketPort"
$env:WS_ALLOWED_ORIGINS="http://localhost:$HttpPort"
$env:LARAVEL_STORAGE_PATH=Join-Path $scratch "integration-$suffix/storage"
foreach($folder in @('app/recordings','app/exports','framework/views','framework/sessions','framework/cache','logs')){New-Item -ItemType Directory -Force -Path (Join-Path $env:LARAVEL_STORAGE_PATH $folder) | Out-Null}
$env:QWEN_ALLOW_TEST_ENDPOINT='false'
$env:QWEN_REGION='cn-beijing'
$env:TEST_COURSE_LABEL='REAL QWEN + OSS / SYNTHETIC SPEECH TEST'
$env:QUEUE_CONNECTION='database'
# All other real credentials and region settings come from the private .env.
$prefixLine=Get-Content -LiteralPath (Join-Path $project '.env') | Where-Object {$_.StartsWith('OSS_PREFIX=')} | Select-Object -First 1
$basePrefix=if($prefixLine){$prefixLine.Substring(11).Trim().Trim('"',"'").Trim('/')}else{'classroom'}
$env:OSS_PREFIX=$basePrefix+'/_integration-tests/'+$suffix
& $php (Join-Path $project 'artisan') migrate --force
if($LASTEXITCODE -ne 0){throw 'Integration migration failed'}
& $php (Join-Path $project 'tests/fixtures/seed.php') | Set-Content -LiteralPath (Join-Path $scratch 'real-gateway-fixture.json') -Encoding utf8
$http=Start-Process -FilePath $php -ArgumentList "-S 127.0.0.1:$HttpPort -t public public/router.php" -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $scratch 'real-http.log') -RedirectStandardError (Join-Path $scratch 'real-http-error.log')
$gateway=Start-Process -FilePath $php -ArgumentList 'bin/gateway.php start' -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $scratch 'real-gateway.log') -RedirectStandardError (Join-Path $scratch 'real-gateway-error.log')
$uploads=Start-Process -FilePath $php -ArgumentList 'artisan queue:work --queue=recordings --sleep=1 --tries=6 --timeout=150' -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $scratch 'real-uploads.log') -RedirectStandardError (Join-Path $scratch 'real-uploads-error.log')
$archives=Start-Process -FilePath $php -ArgumentList 'artisan queue:work --queue=archives --sleep=1 --tries=4 --timeout=7200' -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $scratch 'real-archives.log') -RedirectStandardError (Join-Path $scratch 'real-archives-error.log')
@{http=$http.Id;gateway=$gateway.Id;uploads=$uploads.Id;archives=$archives.Id;database=$database;prefix=$env:OSS_PREFIX;storage=$env:LARAVEL_STORAGE_PATH;origin=$env:APP_URL}|ConvertTo-Json|Set-Content -LiteralPath (Join-Path $scratch 'real-processes.json')
Write-Output 'REAL Qwen + REAL OSS integration services started in an isolated DB and object prefix. Synthetic speech test does consume model usage.'
