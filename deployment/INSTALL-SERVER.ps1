$ErrorActionPreference = 'Stop'

$installLog = Join-Path $PSScriptRoot 'install.log'
Start-Transcript -Path $installLog -Append | Out-Null
trap {
    Write-Host ''
    Write-Host 'INSTALLATION FAILED' -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    try { Stop-Transcript | Out-Null } catch { }
    exit 1
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $projectRoot
$envFile = Join-Path $projectRoot '.env.production'
$envExample = Join-Path $PSScriptRoot '.env.production.example'

function Write-InstallProgress([int] $step, [int] $percent, [string] $message) {
    Write-Host ("[{0}/9] {1,3}%  {2}" -f $step, $percent, $message)
}

function Set-EnvValue([string] $key, [string] $value) {
    $content = if (Test-Path $envFile) { Get-Content -Raw $envFile } else { '' }
    $line = "$key=$value"
    $pattern = "(?m)^$([regex]::Escape($key))=.*$"
    if ($content -match $pattern) {
        $content = [regex]::Replace($content, $pattern, [System.Text.RegularExpressions.MatchEvaluator]{ param($m) $line })
    } else {
        if ($content.Length -gt 0 -and -not $content.EndsWith("`n")) { $content += "`r`n" }
        $content += "$line`r`n"
    }
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($envFile, $content, $utf8NoBom)
}

function Get-EnvValue([string] $key) {
    if (-not (Test-Path $envFile)) { return '' }
    $line = Get-Content $envFile | Where-Object { $_ -match "^$([regex]::Escape($key))=" } | Select-Object -First 1
    if ($null -eq $line) { return '' }
    return ($line -replace "^$([regex]::Escape($key))=", '').Trim().Trim('"')
}

function New-RandomSecret([int] $bytes = 32) {
    $buffer = New-Object byte[] $bytes
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($buffer) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($buffer).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function New-LaravelAppKey() {
    $buffer = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($buffer) } finally { $rng.Dispose() }
    return 'base64:' + [Convert]::ToBase64String($buffer)
}

function Invoke-Compose([string[]] $arguments) {
    $composeArguments = @('compose', '--env-file', $envFile)
    if (-not [string]::IsNullOrWhiteSpace((Get-EnvValue 'CLOUDFLARE_TUNNEL_TOKEN'))) { $composeArguments += @('--profile', 'tunnel') }
    $composeArguments += $arguments
    & docker @composeArguments
    if ($LASTEXITCODE -ne 0) { throw "docker compose command failed: $($arguments -join ' ')" }
}

function Write-HealthDiagnostics() {
    Write-Host 'Collecting safe Docker diagnostics...' -ForegroundColor Yellow
    $diagnosticErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        & docker compose --env-file $envFile --profile tunnel ps 2>&1
        & docker compose --env-file $envFile --profile tunnel logs --tail=80 app 2>&1
        & docker compose --env-file $envFile --profile tunnel logs --tail=80 nginx 2>&1
        & docker compose --env-file $envFile --profile tunnel exec -T nginx getent hosts app 2>&1
    } finally {
        $ErrorActionPreference = $diagnosticErrorActionPreference
    }
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker CLI was not found. Install Docker Desktop or Docker Engine first.'
}
& docker version --format '{{.Server.Version}}' *> $null
if ($LASTEXITCODE -ne 0) { throw 'Docker Engine is not available. Start Docker Desktop and retry.' }
& docker compose version *> $null
if ($LASTEXITCODE -ne 0) { throw 'Docker Compose v2 is not available.' }

if (-not (Test-Path $envFile)) {
    Copy-Item $envExample $envFile
    Write-Host 'Created .env.production from deployment/.env.production.example.'
    Write-Host 'Default installation is local-only at http://localhost:8080.'
}

if ([string]::IsNullOrWhiteSpace((Get-EnvValue 'APP_KEY'))) {
    Set-EnvValue 'APP_KEY' (New-LaravelAppKey)
    Write-Host 'Generated APP_KEY for the first installation.'
} else {
    Write-Host 'Existing APP_KEY preserved.'
}

if ([string]::IsNullOrWhiteSpace((Get-EnvValue 'DB_DATABASE'))) { Set-EnvValue 'DB_DATABASE' 'patient_questionnaires' }
if ([string]::IsNullOrWhiteSpace((Get-EnvValue 'DB_USERNAME'))) { Set-EnvValue 'DB_USERNAME' 'patient_app' }
if ([string]::IsNullOrWhiteSpace((Get-EnvValue 'MARIADB_DATABASE'))) { Set-EnvValue 'MARIADB_DATABASE' (Get-EnvValue 'DB_DATABASE') }
if ([string]::IsNullOrWhiteSpace((Get-EnvValue 'MARIADB_USER'))) { Set-EnvValue 'MARIADB_USER' (Get-EnvValue 'DB_USERNAME') }

$dbPassword = Get-EnvValue 'DB_PASSWORD'
if ([string]::IsNullOrWhiteSpace($dbPassword)) {
    $dbPassword = New-RandomSecret 24
    Set-EnvValue 'DB_PASSWORD' $dbPassword
    Set-EnvValue 'MARIADB_PASSWORD' $dbPassword
    Write-Host 'Generated application database password.'
} elseif ([string]::IsNullOrWhiteSpace((Get-EnvValue 'MARIADB_PASSWORD'))) {
    Set-EnvValue 'MARIADB_PASSWORD' $dbPassword
}
if ([string]::IsNullOrWhiteSpace((Get-EnvValue 'MARIADB_ROOT_PASSWORD'))) {
    Set-EnvValue 'MARIADB_ROOT_PASSWORD' (New-RandomSecret 32)
    Write-Host 'Generated MariaDB root password.'
}
$currentTrustedProxies = Get-EnvValue 'TRUSTED_PROXIES'
if ([string]::IsNullOrWhiteSpace($currentTrustedProxies) -or $currentTrustedProxies -eq '172.30.0.2') {
    Set-EnvValue 'TRUSTED_PROXIES' '172.31.0.0/24'
}

$env:DEPLOY_ENV_FILE = '.env.production'
Write-InstallProgress 1 5 'Preparing server configuration...'
Write-InstallProgress 2 15 'Building application images...'
Invoke-Compose @('config', '--quiet')
$upServices = @('db', 'app', 'nginx', 'queue-worker', 'scheduler')
if (-not [string]::IsNullOrWhiteSpace((Get-EnvValue 'CLOUDFLARE_TUNNEL_TOKEN'))) { $upServices += 'cloudflared' }
Invoke-Compose (@('up', '-d', '--build') + $upServices)
Write-InstallProgress 3 35 'Starting server services...'

Write-InstallProgress 4 50 'Preparing database...'
$healthy = $false
for ($attempt = 1; $attempt -le 60; $attempt++) {
    $healthComposeArguments = @('compose', '--env-file', $envFile)
    if (-not [string]::IsNullOrWhiteSpace((Get-EnvValue 'CLOUDFLARE_TUNNEL_TOKEN'))) { $healthComposeArguments += @('--profile', 'tunnel') }
    & docker @healthComposeArguments exec -T db healthcheck.sh --connect --innodb_initialized *> $null
    if ($LASTEXITCODE -eq 0) { $healthy = $true; break }
    Start-Sleep -Seconds 2
}
if (-not $healthy) { throw 'MariaDB did not become healthy within 120 seconds.' }

Invoke-Compose @('exec', '-T', 'app', 'php', 'artisan', 'migrate', '--force')
Write-InstallProgress 5 65 'Installing roles and permissions...'
Invoke-Compose @('exec', '-T', 'app', 'php', 'artisan', 'db:seed', '--force')
Write-InstallProgress 6 75 'Preparing application cache...'
Invoke-Compose @('exec', '-T', 'app', 'php', 'artisan', 'config:cache')
Invoke-Compose @('exec', '-T', 'app', 'php', 'artisan', 'view:cache')

Write-InstallProgress 7 85 'Creating/verifying administrator...'
$rootStatusOutput = Invoke-Compose @('exec', '-T', 'app', 'php', 'artisan', 'app:platform-admin-status') 2>&1
if ($LASTEXITCODE -ne 0) { throw 'Could not verify platform_admin bootstrap state.' }
$rootStatus = ($rootStatusOutput -join "`n").Trim()
if ($rootStatus -ne 'EXISTS' -and $rootStatus -ne 'MISSING') { throw 'Could not parse platform_admin bootstrap state.' }
if ($rootStatus -eq 'MISSING') {
    Write-Host 'No platform_admin exists. Starting interactive first-admin bootstrap.'
    Invoke-Compose @('exec', 'app', 'php', 'artisan', 'app:create-admin')
    if ($LASTEXITCODE -ne 0) { throw 'First platform_admin bootstrap failed.' }
    Write-Host 'Administrator created. Installation is NOT finished yet. Please wait...' -ForegroundColor Yellow
} else {
    Write-Host 'Bootstrap skipped: platform_admin already exists.'
}

Write-InstallProgress 8 95 'Verifying server health...'
$port = Get-EnvValue 'HTTP_PORT'
if ([string]::IsNullOrWhiteSpace($port)) { $port = '8080' }
$healthOk = $false
$lastHealthError = ''
for ($attempt = 1; $attempt -le 60; $attempt++) {
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri "http://localhost:$port/up" -TimeoutSec 5
        if ([int]$response.StatusCode -eq 200) { $healthOk = $true; break }
    } catch { $lastHealthError = $_.Exception.Message }
    if ($attempt -eq 1 -or $attempt % 10 -eq 0) {
        Write-Host "Health check pending (attempt $attempt/60)..."
    }
    Start-Sleep -Seconds 2
}
if (-not $healthOk) {
    Write-HealthDiagnostics
    $detail = if ([string]::IsNullOrWhiteSpace($lastHealthError)) { '' } else { " Last response: $lastHealthError" }
    throw "Application health check failed at http://localhost:$port/up after 60 attempts.$detail"
}

Write-Host 'Checking login page and production CSS/JS assets...'
$assetSmokeError = ''
try {
    $loginResponse = Invoke-WebRequest -UseBasicParsing -Uri "http://localhost:$port/login" -TimeoutSec 10
    if ([int]$loginResponse.StatusCode -ne 200) { throw "Login page returned HTTP $($loginResponse.StatusCode)." }

    $loginHtml = [string]$loginResponse.Content
    $cssMatch = [regex]::Match($loginHtml, '(?i)(?:href|src)="([^\"]+/build/[^\"]+\.css(?:\?[^\"]*)?)"')
    $jsMatch = [regex]::Match($loginHtml, '(?i)(?:href|src)="([^\"]+/build/[^\"]+\.js(?:\?[^\"]*)?)"')
    if (-not $cssMatch.Success) { throw 'Login page did not expose a production CSS asset URL.' }
    if (-not $jsMatch.Success) { throw 'Login page did not expose a production JS asset URL.' }

    foreach ($assetPath in @($cssMatch.Groups[1].Value, $jsMatch.Groups[1].Value)) {
        $assetUrl = if ($assetPath -match '^https?://') { $assetPath } else { "http://localhost:$port/$($assetPath.TrimStart('/'))" }
        $assetResponse = Invoke-WebRequest -UseBasicParsing -Uri $assetUrl -TimeoutSec 10
        if ([int]$assetResponse.StatusCode -ne 200) { throw "Production asset returned HTTP $($assetResponse.StatusCode): $assetPath" }
        $contentType = [string]$assetResponse.Headers['Content-Type']
        if ($assetPath -match '\.css(?:\?|$)' -and $contentType -notmatch '(?i)text/css') { throw "CSS asset has unexpected Content-Type: $contentType" }
        if ($assetPath -match '\.js(?:\?|$)' -and $contentType -notmatch '(?i)(javascript|ecmascript)') { throw "JS asset has unexpected Content-Type: $contentType" }
    }
} catch { $assetSmokeError = $_.Exception.Message }
if (-not [string]::IsNullOrWhiteSpace($assetSmokeError)) {
    Write-HealthDiagnostics
    throw "Production login/asset smoke check failed: $assetSmokeError"
}
Write-InstallProgress 9 100 'INSTALLATION COMPLETE'
Stop-Transcript | Out-Null
