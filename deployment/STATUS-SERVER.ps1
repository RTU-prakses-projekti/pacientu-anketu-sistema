$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $projectRoot
$envFile = Join-Path $projectRoot '.env.production'

function Get-EnvValue([string] $key) {
    if (-not (Test-Path -LiteralPath $envFile)) { return '' }
    $line = Get-Content -LiteralPath $envFile | Where-Object { $_ -match "^$([regex]::Escape($key))=" } | Select-Object -First 1
    if ($null -eq $line) { return '' }
    return ($line -replace "^$([regex]::Escape($key))=", '').Trim().Trim('"')
}

function Get-ServiceState([string[]] $composeArguments, [string] $service) {
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $containerId = ((& docker @composeArguments ps -q $service 2>$null) -join "`n").Trim()
        if ([string]::IsNullOrWhiteSpace($containerId)) { return 'STOPPED' }
        $state = ((& docker inspect --format '{{.State.Status}}' $containerId 2>$null) -join "`n").Trim()
        if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($state)) { return 'STOPPED' }
        return $state.ToUpperInvariant()
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
}

$port = Get-EnvValue 'HTTP_PORT'
if ([string]::IsNullOrWhiteSpace($port)) { $port = '8080' }

$serviceNames = @('nginx', 'app', 'db', 'queue-worker', 'scheduler')
$tunnelConfigured = -not [string]::IsNullOrWhiteSpace((Get-EnvValue 'CLOUDFLARE_TUNNEL_TOKEN'))
if ($tunnelConfigured) { $serviceNames += 'cloudflared' }
$serviceStates = [ordered]@{}
foreach ($service in $serviceNames) { $serviceStates[$service] = 'STOPPED' }

$dockerAvailable = $true
$dockerError = ''
$composeArguments = @('compose', '--env-file', $envFile)
if ($tunnelConfigured) { $composeArguments += @('--profile', 'tunnel') }

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    $dockerAvailable = $false
    $dockerError = 'Docker CLI is not installed.'
} else {
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        & docker version --format '{{.Server.Version}}' *> $null
        if ($LASTEXITCODE -ne 0) {
            $dockerAvailable = $false
            $dockerError = 'Docker Engine is not available.'
        }
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
}

$composeQuerySucceeded = $false
if ($dockerAvailable) {
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        & docker @composeArguments ps 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0) {
            $composeQuerySucceeded = $true
        } else {
            $dockerError = 'Docker Compose project status could not be read.'
        }
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    if ($composeQuerySucceeded) {
        foreach ($service in $serviceNames) {
            $serviceStates[$service] = Get-ServiceState $composeArguments $service
        }
    }
}

$healthStatus = 'NOT CHECKED'
if ($composeQuerySucceeded) {
    try {
        $health = Invoke-WebRequest -UseBasicParsing -Uri "http://localhost:$port/up" -TimeoutSec 10
        $healthStatus = "HTTP $($health.StatusCode)"
    } catch {
        $healthStatus = 'FAILED'
    }
}

$applicationStatus = if ($composeQuerySucceeded -and $serviceStates['app'] -eq 'RUNNING' -and $serviceStates['nginx'] -eq 'RUNNING' -and $healthStatus -eq 'HTTP 200') { 'RUNNING' } else { 'STOPPED' }

Write-Host ''
Write-Host '============================================================'
Write-Host 'SERVER STATUS'
Write-Host '============================================================'
Write-Host ''
Write-Host 'Application:'
Write-Host $applicationStatus
Write-Host ''
Write-Host 'Local URL:'
Write-Host "http://localhost:$port"
Write-Host ''
Write-Host 'Health:'
Write-Host $healthStatus
Write-Host ''
Write-Host 'Services:'
foreach ($service in $serviceNames) {
    Write-Host ("- {0}: {1}" -f $service, $serviceStates[$service])
}

if (-not $dockerAvailable) {
    Write-Host ''
    Write-Host 'Docker is not running.' -ForegroundColor Yellow
    Write-Host 'Start Docker Desktop and run STATUS-SERVER.bat again.' -ForegroundColor Yellow
    exit 1
}

if (-not $composeQuerySucceeded -or $applicationStatus -eq 'STOPPED') {
    Write-Host ''
    Write-Host 'Server is STOPPED or not healthy.' -ForegroundColor Yellow
    Write-Host 'Run START-SERVER.bat to start the server.' -ForegroundColor Yellow
}

if (-not [string]::IsNullOrWhiteSpace($dockerError)) {
    Write-Host ''
    Write-Host $dockerError -ForegroundColor Yellow
}

exit 0
