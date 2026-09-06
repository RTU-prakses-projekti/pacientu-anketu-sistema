$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $projectRoot

$publicDemoUrlFile = Join-Path $PSScriptRoot 'PUBLIC-DEMO-URL.txt'
$containerName = 'pacientu-anketu-sistema-public-demo'

function Invoke-DockerSafe {
    param([Parameter(Mandatory = $true)][string[]] $Arguments)

    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $rawOutput = & docker @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    return [pscustomobject]@{
        ExitCode = if ($null -eq $exitCode) { 1 } else { [int] $exitCode }
        Text = (@($rawOutput | ForEach-Object { [string] $_ }) -join [Environment]::NewLine)
    }
}

function Fail([string] $Message) {
    Write-Host 'PUBLIC DEMO STOP FAILED' -ForegroundColor Red
    Write-Host $Message -ForegroundColor Red
    exit 1
}

try {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw 'Docker is not installed. The Quick Tunnel was not changed.'
    }

    $dockerVersion = Invoke-DockerSafe -Arguments @('version', '--format', '{{.Server.Version}}')
    if ($dockerVersion.ExitCode -ne 0) {
        throw 'Docker is not running. The Quick Tunnel was not changed.'
    }

    $containerQuery = Invoke-DockerSafe -Arguments @('ps', '-aq', '--filter', "name=^/$containerName$")
    if ($containerQuery.ExitCode -ne 0) { throw 'Could not inspect the Quick Tunnel container.' }
    $containerIds = @($containerQuery.Text -split '\s+' | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
    foreach ($containerId in $containerIds) {
        $removeResult = Invoke-DockerSafe -Arguments @('rm', '-f', $containerId)
        if ($removeResult.ExitCode -ne 0) {
            throw "Could not stop the Quick Tunnel container: $(($removeResult.Text -replace '\s+', ' ').Trim())"
        }
    }

    if (Test-Path -LiteralPath $publicDemoUrlFile) {
        Remove-Item -LiteralPath $publicDemoUrlFile -Force
    }

    Write-Host '============================================================'
    Write-Host 'PUBLIC DEMO STOPPED' -ForegroundColor Green
    Write-Host '============================================================'
    Write-Host ''
    Write-Host 'The Cloudflare Quick Tunnel was stopped and removed.'
    Write-Host 'The Laravel environment and all local services were not changed.'
    Write-Host 'The local server at http://localhost:8080 remains untouched.'
}
catch {
    Fail $_.Exception.Message
}
