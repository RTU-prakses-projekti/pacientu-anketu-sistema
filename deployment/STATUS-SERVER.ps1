$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $projectRoot
$envFile = Join-Path $projectRoot '.env.production'
if (-not (Test-Path $envFile)) { throw 'Missing .env.production. Run INSTALL-SERVER.bat first.' }
$env:DEPLOY_ENV_FILE = '.env.production'
& docker compose --env-file $envFile --profile tunnel ps
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
$portLine = Get-Content $envFile | Where-Object { $_ -match '^HTTP_PORT=' } | Select-Object -First 1
$port = if ($portLine) { ($portLine -replace '^HTTP_PORT=', '').Trim() } else { '8080' }
try {
    $health = Invoke-WebRequest -UseBasicParsing -Uri "http://localhost:$port/up" -TimeoutSec 10
    Write-Host "app/web health: HTTP $($health.StatusCode)"
} catch {
    Write-Warning "app/web health check failed: $($_.Exception.Message)"
}
Write-Host 'database: see db container health in the compose output'
Write-Host 'queue worker: see queue-worker container status in the compose output'
Write-Host 'scheduler: see scheduler container status in the compose output'
Write-Host 'tunnel: enabled automatically when CLOUDFLARE_TUNNEL_TOKEN is configured'
