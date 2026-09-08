param(
    [switch] $RestoreDnsState,
    [string] $DnsStatePath = ''
)

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $projectRoot

$publicDemoUrlFile = Join-Path $PSScriptRoot 'PUBLIC-DEMO-URL.txt'
$dnsStateFile = if ([string]::IsNullOrWhiteSpace($DnsStatePath)) { Join-Path $PSScriptRoot 'PUBLIC-DEMO-DNS-STATE.json' } else { $DnsStatePath }
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

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Read-DnsState {
    if (-not (Test-Path -LiteralPath $dnsStateFile)) { return $null }
    $encoding = New-Object System.Text.UTF8Encoding($false)
    try { return [System.IO.File]::ReadAllText($dnsStateFile, $encoding) | ConvertFrom-Json } catch { throw 'The saved temporary DNS state is invalid.' }
}

function Restore-DnsStateFromFile {
    $state = Read-DnsState
    if ($null -eq $state) { return }
    $interfaceIndex = 0
    if (-not [int]::TryParse([string] $state.InterfaceIndex, [ref] $interfaceIndex) -or $interfaceIndex -le 0) { throw 'The saved temporary DNS state has no valid interface index.' }
    $previousDnsServers = @($state.PreviousDnsServers | ForEach-Object { [string] $_ } | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
    if ($previousDnsServers.Count -eq 0) {
        Set-DnsClientServerAddress -InterfaceIndex $interfaceIndex -ResetServerAddresses -ErrorAction Stop
    } else {
        Set-DnsClientServerAddress -InterfaceIndex $interfaceIndex -ServerAddresses $previousDnsServers -ErrorAction Stop
    }
    $flush = & ipconfig.exe /flushdns 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'DNS cache flush failed while restoring the previous configuration.' }
    Remove-Item -LiteralPath $dnsStateFile -Force -ErrorAction Stop
}

function Invoke-ElevatedDnsRestore {
    $powerShellPath = Join-Path $PSHOME 'powershell.exe'
    $quotedScript = '"{0}"' -f $PSCommandPath
    $quotedState = '"{0}"' -f $dnsStateFile
    $process = Start-Process -FilePath $powerShellPath -Verb RunAs -Wait -PassThru -ArgumentList @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $quotedScript,
        '-RestoreDnsState', '-DnsStatePath', $quotedState
    )
    return $process.ExitCode -eq 0
}

function Fail([string] $Message) {
    Write-Host 'PUBLIC DEMO STOP FAILED' -ForegroundColor Red
    Write-Host $Message -ForegroundColor Red
    exit 1
}

if ($RestoreDnsState) {
    try {
        Restore-DnsStateFromFile
        exit 0
    } catch {
        Write-Error $_.Exception.Message
        exit 1
    }
}

try {
    if (Test-Path -LiteralPath $dnsStateFile) {
        if (Test-IsAdministrator) {
            Restore-DnsStateFromFile
        } elseif (-not (Invoke-ElevatedDnsRestore)) {
            throw 'Windows denied elevation while restoring the temporary DNS configuration. The state file was preserved for a retry.'
        }
    }

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
