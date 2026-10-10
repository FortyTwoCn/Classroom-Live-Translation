$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$workspace = Split-Path -Parent (Split-Path -Parent $project)
$runtime = Join-Path $workspace 'work/runtime'
$php = Join-Path $runtime 'php/php.exe'
$scratch = Join-Path $workspace 'work'
# These explicit fake credentials only run against a localhost provider and isolated test DB.
$env:APP_ENV = 'testing'
$env:DB_DATABASE = 'classroom_test'
$env:APP_URL = 'http://localhost:18080'
$env:WS_LISTEN = 'websocket://127.0.0.1:18081'
$env:WS_PUBLIC_URL = 'ws://localhost:18081'
$env:WS_ALLOWED_ORIGINS = 'http://localhost:18080'
$env:QWEN_ALLOW_TEST_ENDPOINT = 'true'
$env:QWEN_WS_ENDPOINT = 'ws://127.0.0.1:18082/api-ws/v1/realtime'
$env:QWEN_API_KEY = 'explicit-simulation-no-real-credential'
$env:QWEN_WORKSPACE_ID = 'fixture'
$env:OSS_ACCESS_KEY_ID = 'explicit-simulation-key'
$env:OSS_ACCESS_KEY_SECRET = 'explicit-simulation-secret'
$env:OSS_BUCKET = 'fixture-bucket'
$env:OSS_ENDPOINT = 'https://s3.oss-cn-hangzhou.aliyuncs.com'
$env:OSS_REGION = 'cn-hangzhou'
& $php (Join-Path $project 'tests/fixtures/seed.php') | Set-Content -LiteralPath (Join-Path $scratch 'gateway-fixture.json') -Encoding utf8
$http = Start-Process -FilePath $php -ArgumentList '-S 127.0.0.1:18080 -t public public/router.php' -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $scratch 'test-http.log') -RedirectStandardError (Join-Path $scratch 'test-http-error.log')
$mock = Start-Process -FilePath $php -ArgumentList 'tests/fixtures/mock-qwen.php start' -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $scratch 'mock-qwen.log') -RedirectStandardError (Join-Path $scratch 'mock-qwen-error.log')
$gateway = Start-Process -FilePath $php -ArgumentList 'bin/gateway.php start' -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $scratch 'test-gateway.log') -RedirectStandardError (Join-Path $scratch 'test-gateway-error.log')
@{http=$http.Id; mock=$mock.Id; gateway=$gateway.Id} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $scratch 'test-processes.json')
Write-Output 'Isolated test services started on localhost ports 18080/18081/18082; no cloud requests.'
