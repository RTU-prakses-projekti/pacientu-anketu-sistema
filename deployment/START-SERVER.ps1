$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $projectRoot
$envFile = Join-Path $projectRoot '.env.production'
if (-not (Test-Path $envFile)) { throw 'Missing .env.production. Run INSTALL-SERVER.bat first.' }
$env:DEPLOY_ENV_FILE = '.env.production'
function Get-EnvValue([string] $key) {
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    $content = [System.IO.File]::ReadAllText($envFile, $utf8NoBom)
    $line = [regex]::Match($content, "(?m)^$([regex]::Escape($key))=([^\r\n]*)")
    if (-not $line.Success) { return '' }
    return $line.Groups[2].Value.Trim().Trim('"')
}
$profileArgs = @()
$tunnelToken = Get-EnvValue 'CLOUDFLARE_TUNNEL_TOKEN'
if (-not [string]::IsNullOrWhiteSpace($tunnelToken)) { $profileArgs = @('--profile', 'tunnel') }
& docker compose --env-file $envFile @profileArgs up -d
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
docker compose --env-file $envFile @profileArgs ps
$port = Get-EnvValue 'HTTP_PORT'
if ([string]::IsNullOrWhiteSpace($port)) { $port = '8080' }
Write-Host "Health URL: http://localhost:$port/up"
