# Windows localhost deployment smoke. Requires the documented portable runtimes.
# Uses a self-signed certificate, fake provider credentials and an isolated DB.
$ErrorActionPreference='Stop'
$project=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$workspace=Split-Path -Parent (Split-Path -Parent $project)
$runtime=Join-Path $workspace 'work/runtime'
$php=Join-Path $runtime 'php/php.exe';$cgi=Join-Path $runtime 'php/php-cgi.exe';$pg=Join-Path $runtime 'pg/pgsql/bin/psql.exe'
$nginxHome=Join-Path $runtime 'nginx/nginx-1.30.5';$nginx=Join-Path $nginxHome 'nginx.exe';$node=(Get-Command node).Source
if(-not(Test-Path -LiteralPath $nginx)){throw 'Download official nginx/Windows1.30.5 into work/runtime/nginx; see deployment docs.'}
foreach($port in @(21080,21443,21900,21081,21082)){if(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue){throw "TLS test port $port occupied; no processes stopped."}}
$suffix=[guid]::NewGuid().ToString('N').Substring(0,12);$run=Join-Path $workspace ('work/local-tls-'+$suffix);$storage=Join-Path $run 'storage'
foreach($folder in @('logs','temp')){New-Item -ItemType Directory -Force -Path (Join-Path $run $folder) | Out-Null}
foreach($folder in @('app/recordings','framework/cache/data','framework/sessions','framework/views','logs')){New-Item -ItemType Directory -Force -Path (Join-Path $storage $folder) | Out-Null}
$env:APP_ENV='testing';$env:APP_DEBUG='false';$env:LARAVEL_STORAGE_PATH=$storage;$env:DB_DATABASE='classroom_integration_'+$suffix
$env:PGPASSWORD=[IO.File]::ReadAllText((Join-Path $runtime 'pg-password.txt')).Trim()
& $pg -h 127.0.0.1 -p 55432 -U classroom_dev -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE $($env:DB_DATABASE) OWNER classroom_dev"
if($LASTEXITCODE -ne 0){throw 'TLS fixture DB creation failed'}
Remove-Item Env:PGPASSWORD
$env:APP_URL='https://localhost:21443';$env:SESSION_SECURE_COOKIE='true';$env:CACHE_STORE='database';$env:SESSION_DRIVER='database';$env:QUEUE_CONNECTION='database'
$env:WS_LISTEN='websocket://127.0.0.1:21081';$env:WS_PUBLIC_URL='wss://localhost:21443/ws';$env:WS_ALLOWED_ORIGINS='https://localhost:21443';$env:MOCK_QWEN_LISTEN='websocket://127.0.0.1:21082'
$env:QWEN_API_KEY='explicit-tls-mock-key';$env:QWEN_WORKSPACE_ID='fixture';$env:QWEN_REGION='cn-beijing';$env:QWEN_ALLOW_TEST_ENDPOINT='true';$env:QWEN_WS_ENDPOINT='ws://127.0.0.1:21082/api-ws/v1/realtime'
$env:OSS_ACCESS_KEY_ID='explicit-tls-mock-key';$env:OSS_ACCESS_KEY_SECRET='explicit-tls-mock-secret';$env:OSS_SESSION_TOKEN='';$env:OSS_ENDPOINT='https://s3.oss-cn-hangzhou.aliyuncs.com';$env:OSS_REGION='cn-hangzhou';$env:OSS_BUCKET='explicit-tls-fixture';$env:OSS_PREFIX='tls-fixture';$env:TEST_COURSE_LABEL='EXPLICIT LOCAL TLS MOCK / no cloud requests'
$env:PHP_FCGI_MAX_REQUESTS='1000'
Push-Location $project
$children=[Collections.Generic.List[object]]::new()
try{
 & $php artisan migrate --force
 if($LASTEXITCODE -ne 0){throw 'TLS migrations failed'}
 $fixturePath=Join-Path $run 'fixture.json'; & $php tests/fixtures/seed.php | Set-Content -LiteralPath $fixturePath -Encoding utf8
 if($LASTEXITCODE -ne 0){throw 'TLS seed failed'}
 & $php tests/fixtures/local-tls.php $run (Join-Path $runtime 'php/extras/ssl/openssl.cnf')
 if($LASTEXITCODE -ne 0){throw 'TLS certificate generation failed'}
 # Use the delivered HTTPS vhost, only substituting localhost ports/paths.
 $vhost=Get-Content -LiteralPath deploy/nginx-https.conf.example -Raw
 $runUnix=$run.Replace('\','/');$projectUnix=$project.Replace('\','/')
 $vhost=$vhost.Replace('listen 80;','listen 127.0.0.1:21080;').Replace('listen 443 ssl;','listen 127.0.0.1:21443 ssl;').Replace('classroom.example.edu','localhost').Replace('https://$host$request_uri','https://localhost:21443$request_uri').Replace('/var/www/classroom',$projectUnix).Replace('/etc/nginx/certs/fullchain.pem',$runUnix+'/cert.pem').Replace('/etc/nginx/certs/privkey.pem',$runUnix+'/key.pem').Replace('app:9000','127.0.0.1:21900').Replace('gateway:8081','127.0.0.1:21081').Replace('/var/log/nginx/access.log',$runUnix+'/access.log')
 Copy-Item -LiteralPath (Join-Path $nginxHome 'conf/fastcgi_params') -Destination (Join-Path $run 'fastcgi_params')
 Copy-Item -LiteralPath (Join-Path $nginxHome 'conf/mime.types') -Destination (Join-Path $run 'mime.types')
 $config="worker_processes 1;`nerror_log $runUnix/error.log;`npid $runUnix/nginx.pid;`nevents { worker_connections 1024; }`nhttp { include mime.types; default_type application/octet-stream; $vhost }"
 [IO.File]::WriteAllText((Join-Path $run 'nginx.conf'),$config)
 & $nginx -p ($runUnix+'/') -c nginx.conf -t
 if($LASTEXITCODE -ne 0){throw 'Delivered Nginx HTTPS configuration failed native syntax validation'}
 foreach($spec in @(@{name='cgi';exe=$cgi;args=@('-b','127.0.0.1:21900')},@{name='mock';exe=$php;args=@('tests/fixtures/mock-qwen.php','start')},@{name='gateway';exe=$php;args=@('bin/gateway.php','start')},@{name='nginx';exe=$nginx;args=@('-p',('"'+$runUnix+'/"'),'-c','nginx.conf')})){
  $p=Start-Process -FilePath $spec.exe -ArgumentList $spec.args -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $run ($spec.name+'.log')) -RedirectStandardError (Join-Path $run ($spec.name+'-error.log'));$children.Add(@{name=$spec.name;pid=$p.Id;started=$p.StartTime.ToUniversalTime().ToString('o')})
 }
 $deadline=[DateTime]::UtcNow.AddSeconds(20)
 do{Start-Sleep -Milliseconds 200;if([DateTime]::UtcNow -gt $deadline){throw 'Local TLS service startup deadline'}}until(@(Get-NetTCPConnection -State Listen -LocalPort 21080,21443,21900,21081,21082 -ErrorAction SilentlyContinue).Count -ge 5)
 & $node tests/e2e/local-tls.mjs $fixturePath
 if($LASTEXITCODE -ne 0){throw 'Local TLS browser test failed; isolated logs/report retained'}
}finally{
 if($children | Where-Object {$_.name -eq 'nginx'}){& $nginx -p ($runUnix+'/') -c nginx.conf -s quit;Start-Sleep -Milliseconds 500}
 foreach($entry in $children){$p=Get-Process -Id $entry.pid -ErrorAction SilentlyContinue;if($p -and $p.StartTime.ToUniversalTime().ToString('o') -eq $entry.started){Stop-Process -Id $p.Id}}
 Pop-Location
}
