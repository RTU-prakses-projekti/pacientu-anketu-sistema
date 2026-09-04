$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $projectRoot
$envFile = Join-Path $projectRoot '.env.production'
$envExample = Join-Path $PSScriptRoot '.env.production.example'
$installLog = Join-Path $PSScriptRoot 'install.log'
$env:DEPLOY_ENV_FILE = 'deployment/.env.production.example'

Write-Host 'WARNING: this will permanently delete this project Docker installation.' -ForegroundColor Yellow
Write-Host 'All patient database data, private storage, exports, attachments, containers, networks and volumes for this project will be lost.' -ForegroundColor Yellow
Write-Host 'Create and verify a backup before continuing if any data must be preserved.' -ForegroundColor Yellow
Write-Host ''
$confirmation = Read-Host 'Type DELETE exactly to continue'
if ($confirmation -cne 'DELETE') {
    Write-Host 'Uninstall cancelled. Nothing was changed.' -ForegroundColor Green
    exit 0
}

function Invoke-ProjectCompose([string[]] $arguments) {
    $composeArguments = @('compose', '--project-name', 'pacientu-anketu-sistema', '--env-file', $envExample, '--profile', 'tunnel') + $arguments
    & docker @composeArguments
    if ($LASTEXITCODE -ne 0) { throw "docker compose command failed: $($arguments -join ' ')" }
}

function Test-ProjectImage([string] $image) {
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        & docker image inspect $image *> $null
        return $LASTEXITCODE -eq 0
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
}

function Remove-ProjectImage([string] $image) {
    if (-not (Test-ProjectImage $image)) { return }

    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $removeOutput = & docker image rm $image 2>&1
        $removeExitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    if ($removeExitCode -eq 0) { return }
    if ($removeOutput -match 'No such image|image .* not found' -and -not (Test-ProjectImage $image)) { return }
    throw "Could not remove project image $image."
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Docker CLI was not found.' }
& docker version --format '{{.Server.Version}}' *> $null
if ($LASTEXITCODE -ne 0) { throw 'Docker Engine is not available.' }
& docker compose version *> $null
if ($LASTEXITCODE -ne 0) { throw 'Docker Compose v2 is not available.' }

# The example env is used only to resolve Compose interpolation; no production
# secret file is read or printed during uninstall.
Invoke-ProjectCompose @('down', '--volumes', '--remove-orphans', '--rmi', 'local')

foreach ($image in @('pacientu-anketu-sistema:latest', 'pacientu-anketu-sistema:nginx')) { Remove-ProjectImage $image }

foreach ($path in @($envFile, $installLog)) {
    if (Test-Path -LiteralPath $path) {
        Remove-Item -LiteralPath $path -Force
    }
}

Write-Host ''
Write-Host 'UNINSTALL COMPLETE' -ForegroundColor Green
Write-Host 'Only the pacientu-anketu-sistema Compose project resources and local deployment files were removed.'
Write-Host 'Run INSTALL-SERVER.bat to create a fresh local-only installation.'
