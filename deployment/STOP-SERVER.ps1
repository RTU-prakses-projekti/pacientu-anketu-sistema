$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $projectRoot
$envFile = Join-Path $projectRoot '.env.production'
if (-not (Test-Path $envFile)) { throw 'Missing .env.production. Run INSTALL-SERVER.bat first.' }
$env:DEPLOY_ENV_FILE = '.env.production'
& docker compose --env-file $envFile --profile tunnel stop
exit $LASTEXITCODE
