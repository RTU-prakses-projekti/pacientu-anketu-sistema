$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $projectRoot
$envFile = Join-Path $projectRoot '.env.production'
if (-not (Test-Path $envFile)) { throw 'Missing .env.production. Run INSTALL-SERVER.bat first.' }

function Get-EnvValue([string] $key) {
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    $content = [System.IO.File]::ReadAllText($envFile, $utf8NoBom)
    $line = [regex]::Match($content, "(?m)^$([regex]::Escape($key))=([^\r\n]*)")
    if (-not $line.Success) { return '' }
    return $line.Groups[2].Value.Trim().Trim('"')
}

function Set-EnvValue([string] $key, [string] $value) {
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    $content = [System.IO.File]::ReadAllText($envFile, $utf8NoBom)
    $lines = [regex]::Split($content, "\r?\n")
    $keyPattern = "^$([regex]::Escape($key))="
    $updated = $false
    for ($index = 0; $index -lt $lines.Length; $index++) {
        if ($lines[$index] -match $keyPattern) {
            $lines[$index] = "$key=$value"
            $updated = $true
            break
        }
    }
    if (-not $updated) { $lines += "$key=$value" }
    [System.IO.File]::WriteAllText($envFile, [string]::Join([Environment]::NewLine, $lines), $utf8NoBom)
}

function ConvertFrom-SecureStringPlain([Security.SecureString] $secureValue) {
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureValue)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
}

function Invoke-Compose([string[]] $arguments) {
    $composeArguments = @('compose', '--env-file', $envFile, '--profile', 'tunnel') + $arguments
    & docker @composeArguments
    if ($LASTEXITCODE -ne 0) { throw "docker compose command failed: $($arguments -join ' ')" }
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Docker CLI was not found. Install Docker Desktop first.' }
& docker version --format '{{.Server.Version}}' *> $null
if ($LASTEXITCODE -ne 0) { throw 'Docker Engine is not available. Start Docker Desktop and retry.' }
& docker compose version *> $null
if ($LASTEXITCODE -ne 0) { throw 'Docker Compose v2 is not available.' }

$publicUrl = Read-Host 'Public HTTPS URL (for example https://questionnaires.example.org)'
$parsedUrl = $null
if (-not [Uri]::TryCreate($publicUrl.TrimEnd('/'), [UriKind]::Absolute, [ref]$parsedUrl) -or $parsedUrl.Scheme -ne 'https' -or [string]::IsNullOrWhiteSpace($parsedUrl.Host)) {
    throw 'A valid HTTPS URL is required.'
}
$secureToken = Read-Host 'Cloudflare Tunnel token' -AsSecureString
$tunnelToken = ConvertFrom-SecureStringPlain $secureToken
if ([string]::IsNullOrWhiteSpace($tunnelToken)) { throw 'A non-empty Cloudflare Tunnel token is required.' }

Set-EnvValue 'APP_URL' $publicUrl.TrimEnd('/')
Set-EnvValue 'SESSION_SECURE_COOKIE' 'true'
Set-EnvValue 'SESSION_COOKIE' 'pacientu_anketu_sistema_session'
Set-EnvValue 'CLOUDFLARE_TUNNEL_TOKEN' $tunnelToken
Set-EnvValue 'TRUSTED_PROXIES' '172.31.0.0/24'
$env:DEPLOY_ENV_FILE = '.env.production'

Invoke-Compose @('config', '--quiet')
Invoke-Compose @('up', '-d', '--force-recreate')
Invoke-Compose @('exec', '-T', 'app', 'php', 'artisan', 'config:cache')
Invoke-Compose @('restart')

$port = Get-EnvValue 'HTTP_PORT'
if ([string]::IsNullOrWhiteSpace($port)) { $port = '8080' }
$healthOk = $false
for ($attempt = 1; $attempt -le 60; $attempt++) {
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri "http://localhost:$port/up" -TimeoutSec 5
        if ([int]$response.StatusCode -eq 200) { $healthOk = $true; break }
    } catch { }
    Start-Sleep -Seconds 2
}
if (-not $healthOk) { throw "Application health check failed at http://localhost:$port/up." }

Write-Host ''
Write-Host 'PUBLIC SERVER CONFIGURED' -ForegroundColor Green
Write-Host "Public URL: $(Get-EnvValue 'APP_URL')"
Write-Host 'Tunnel profile is enabled. Future starts are handled by START-SERVER.bat.'
