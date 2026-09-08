$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $projectRoot
$envFile = Join-Path $projectRoot '.env.production'
$composeEnvFile = '.env.production'
if (-not (Test-Path $envFile)) { throw 'Missing .env.production. Run INSTALL-SERVER.bat first.' }
$env:DEPLOY_ENV_FILE = '.env.production'
function Get-EnvValue([string] $key) {
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    $content = [System.IO.File]::ReadAllText($envFile, $utf8NoBom)
    $line = [regex]::Match($content, "(?m)^$([regex]::Escape($key))=([^\r\n]*)")
    if (-not $line.Success) { return '' }
    return $line.Groups[1].Value.Trim().Trim('"')
}

function Assert-DatabaseEnvironment {
    $dbName = Get-EnvValue 'DB_DATABASE'
    $mariaDbName = Get-EnvValue 'MARIADB_DATABASE'
    $dbUser = Get-EnvValue 'DB_USERNAME'
    $mariaDbUser = Get-EnvValue 'MARIADB_USER'
    $dbPassword = Get-EnvValue 'DB_PASSWORD'
    $mariaDbPassword = Get-EnvValue 'MARIADB_PASSWORD'

    if ([string]::IsNullOrWhiteSpace($dbName) -or [string]::IsNullOrWhiteSpace($mariaDbName) -or $dbName -ne $mariaDbName) {
        throw 'DB_DATABASE and MARIADB_DATABASE must be equal and non-empty in .env.production. START-SERVER did not change credentials.'
    }
    if ([string]::IsNullOrWhiteSpace($dbUser) -or [string]::IsNullOrWhiteSpace($mariaDbUser) -or $dbUser -ne $mariaDbUser) {
        throw 'DB_USERNAME and MARIADB_USER must be equal and non-empty in .env.production. START-SERVER did not change credentials.'
    }
    if ([string]::IsNullOrWhiteSpace($dbPassword) -or [string]::IsNullOrWhiteSpace($mariaDbPassword) -or $dbPassword -ne $mariaDbPassword) {
        throw 'DB_PASSWORD and MARIADB_PASSWORD must be equal and non-empty in .env.production. START-SERVER did not change credentials.'
    }
}

Assert-DatabaseEnvironment

$profileArgs = @()
$tunnelToken = Get-EnvValue 'CLOUDFLARE_TUNNEL_TOKEN'
if (-not [string]::IsNullOrWhiteSpace($tunnelToken)) { $profileArgs = @('--profile', 'tunnel') }
& docker compose --env-file $composeEnvFile @profileArgs up -d
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
docker compose --env-file $composeEnvFile @profileArgs ps
$port = Get-EnvValue 'HTTP_PORT'
if ([string]::IsNullOrWhiteSpace($port)) { $port = '8080' }
Write-Host "Health URL: http://localhost:$port/up"
