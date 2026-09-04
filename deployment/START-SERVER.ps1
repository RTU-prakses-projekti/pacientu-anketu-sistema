$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $projectRoot
$envFile = Join-Path $projectRoot '.env.production'
if (-not (Test-Path $envFile)) { throw 'Missing .env.production. Run INSTALL-SERVER.bat first.' }
$env:DEPLOY_ENV_FILE = '.env.production'
$profileArgs = @()
$tunnelToken = (Get-Content $envFile | Where-Object { $_ -match '^CLOUDFLARE_TUNNEL_TOKEN=' } | Select-Object -First 1)
if ($tunnelToken -and -not [string]::IsNullOrWhiteSpace(($tunnelToken -replace '^CLOUDFLARE_TUNNEL_TOKEN=', '').Trim().Trim('"'))) { $profileArgs = @('--profile', 'tunnel') }
& docker compose --env-file $envFile @profileArgs up -d
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
docker compose --env-file $envFile @profileArgs ps
$port = ((Get-Content $envFile | Where-Object { $_ -match '^HTTP_PORT=' } | Select-Object -First 1) -replace '^HTTP_PORT=', '').Trim()
if ([string]::IsNullOrWhiteSpace($port)) { $port = '8080' }
Write-Host "Health URL: http://localhost:$port/up"
