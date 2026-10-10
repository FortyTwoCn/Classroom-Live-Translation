$ErrorActionPreference='Stop'
$project=Split-Path -Parent $PSScriptRoot
$workspace=Split-Path -Parent (Split-Path -Parent $project)
$scratch=Join-Path $workspace 'work'
$metadataPath=Join-Path $scratch 'real-processes.json'
$metadata=Get-Content -LiteralPath $metadataPath -Raw | ConvertFrom-Json
if($metadata.database -notmatch '^classroom_integration_[a-f0-9]+$' -or $metadata.origin -ne 'http://localhost:19080'){throw 'Dedicated phase C integration services required'}
$gatewayProcess=Get-CimInstance Win32_Process -Filter "ProcessId=$($metadata.gateway)"
if(!$gatewayProcess -or !$gatewayProcess.CommandLine.Contains('bin/gateway.php start') -or !$gatewayProcess.ExecutablePath.EndsWith('php.exe')){throw 'Recorded gateway process identity does not match'}
Stop-Process -Id $metadata.gateway
$env:APP_ENV='testing';$env:DB_DATABASE=$metadata.database;$env:APP_URL=$metadata.origin
$env:WS_LISTEN='websocket://127.0.0.1:19081';$env:WS_PUBLIC_URL='ws://localhost:19081';$env:WS_ALLOWED_ORIGINS=$metadata.origin
$env:LARAVEL_STORAGE_PATH=$metadata.storage;$env:OSS_PREFIX=$metadata.prefix
$env:QWEN_ALLOW_TEST_ENDPOINT='false';$env:QWEN_REGION='cn-beijing';$env:QUEUE_CONNECTION='database'
$gateway=Start-Process -FilePath (Join-Path $scratch 'runtime/php/php.exe') -ArgumentList 'bin/gateway.php start' -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $scratch 'real-gateway.log') -RedirectStandardError (Join-Path $scratch 'real-gateway-error.log')
$metadata.gateway=$gateway.Id
$metadata|ConvertTo-Json|Set-Content -LiteralPath $metadataPath
Write-Output 'Only the recorded dedicated phase C gateway was restarted.'
