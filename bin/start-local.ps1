$ErrorActionPreference='Stop'
$project=Split-Path -Parent $PSScriptRoot
$workspace=Split-Path -Parent (Split-Path -Parent $project)
$runtime=Join-Path $workspace 'work/runtime'
$php=Join-Path $runtime 'php/php.exe'
if(-not (Test-Path -LiteralPath $php)){throw 'Portable runtime missing; use Docker Compose or your own PHP 8.5 runtime. See README.'}
$logdir=Join-Path $project 'storage/logs'
New-Item -ItemType Directory -Force -Path $logdir | Out-Null
Push-Location $project
try {
 & $php artisan migrate --force
 if($LASTEXITCODE -ne 0){throw 'Database migration failed; check local PostgreSQL and DB settings.'}
 $http=Start-Process -FilePath $php -ArgumentList '-S 127.0.0.1:8080 -t public public/router.php' -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $logdir 'http.log') -RedirectStandardError (Join-Path $logdir 'http-error.log')
 $gateway=Start-Process -FilePath $php -ArgumentList 'bin/gateway.php start' -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $logdir 'gateway.log') -RedirectStandardError (Join-Path $logdir 'gateway-error.log')
 $uploads=Start-Process -FilePath $php -ArgumentList 'artisan queue:work --queue=recordings --sleep=1 --tries=6 --timeout=150' -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $logdir 'uploads.log') -RedirectStandardError (Join-Path $logdir 'uploads-error.log')
 $archives=Start-Process -FilePath $php -ArgumentList 'artisan queue:work --queue=archives --sleep=1 --tries=4 --timeout=7200' -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $logdir 'archives.log') -RedirectStandardError (Join-Path $logdir 'archives-error.log')
 @{http=$http.Id;gateway=$gateway.Id;uploads=$uploads.Id;archives=$archives.Id}|ConvertTo-Json|Set-Content -LiteralPath (Join-Path $project 'storage/local-processes.json')
 Write-Output 'Local services started: http://localhost:8080. Create an administrator with artisan classroom:admin. No microphone or model session is started automatically.'
} finally {Pop-Location}
