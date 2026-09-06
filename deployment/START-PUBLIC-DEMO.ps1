$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $projectRoot
$envFile = Join-Path $projectRoot '.env.production'
$publicDemoUrlFile = Join-Path $PSScriptRoot 'PUBLIC-DEMO-URL.txt'
$diagnosticFile = Join-Path $PSScriptRoot 'PUBLIC-DEMO-DIAGNOSTIC.txt'
$publicDemoUrlDisplay = 'deployment\PUBLIC-DEMO-URL.txt'
$diagnosticDisplay = 'deployment\PUBLIC-DEMO-DIAGNOSTIC.txt'
$containerName = 'pacientu-anketu-sistema-public-demo'
$cloudflaredImage = 'cloudflare/cloudflared:latest'
$containerId = ''
$appId = ''
$nginxId = ''
$edgeNetwork = ''
$publicUrl = ''

function Write-Utf8NoBom([string] $Path, [string] $Content) {
    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $Content, $encoding)
}

function Append-Utf8NoBom([string] $Path, [string] $Content) {
    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::AppendAllText($Path, $Content + [Environment]::NewLine, $encoding)
}

function Protect-DiagnosticText([string] $Text) {
    if ($null -eq $Text) { return '' }
    return ($Text -replace '(?im)(APP_KEY|DB_PASSWORD|DB_USERNAME|CLOUDFLARE_TUNNEL_TOKEN|TUNNEL_TOKEN|PASSWORD|SECRET|TOKEN)(\s*[:=]\s*)["'']?[^\s,"'']+', '$1$2[REDACTED]')
}

function Write-Diagnostic([string] $Message) {
    Append-Utf8NoBom $diagnosticFile ("[$((Get-Date).ToString('yyyy-MM-dd HH:mm:ss'))] $Message")
}

function Write-DiagnosticBlock([string] $Title, [string] $Content) {
    Write-Diagnostic $Title
    if (-not [string]::IsNullOrWhiteSpace($Content)) { Append-Utf8NoBom $diagnosticFile (Protect-DiagnosticText $Content) }
}

function Remove-PublicDemoUrlFile {
    if (Test-Path -LiteralPath $publicDemoUrlFile) { Remove-Item -LiteralPath $publicDemoUrlFile -Force -ErrorAction SilentlyContinue }
}

function Save-PublicDemoUrlFile([string] $Status) {
    $content = @(
        "Public URL: $publicUrl"
        "Login: $publicUrl/login"
        "Created: $((Get-Date).ToString('yyyy-MM-dd HH:mm:ss zzz'))"
        "Status: $Status"
    ) -join [Environment]::NewLine
    Write-Utf8NoBom $publicDemoUrlFile ($content + [Environment]::NewLine)
}

function Get-ShortText([string] $Text) {
    if ([string]::IsNullOrWhiteSpace($Text)) { return '' }
    $shortText = ($Text -replace '\s+', ' ').Trim()
    if ($shortText.Length -gt 240) { return $shortText.Substring(0, 240) + '...' }
    return $shortText
}

function Get-EnvValue([string] $Key) {
    if (-not (Test-Path -LiteralPath $envFile)) { return '' }
    $encoding = New-Object System.Text.UTF8Encoding($false)
    $content = [System.IO.File]::ReadAllText($envFile, $encoding)
    $line = [regex]::Match($content, "(?m)^$([regex]::Escape($Key))=([^\r\n]*)")
    if (-not $line.Success) { return '' }
    return $line.Groups[2].Value.Trim().Trim('"')
}

function Invoke-DockerSafe {
    param([Parameter(Mandatory = $true)][string[]] $Arguments)
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $rawOutput = & docker @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previousErrorActionPreference }
    return [pscustomobject]@{
        ExitCode = if ($null -eq $exitCode) { 1 } else { [int] $exitCode }
        Text = (@($rawOutput | ForEach-Object { [string] $_ }) -join [Environment]::NewLine)
    }
}

function Invoke-ComposeSafe([string[]] $Arguments) {
    return Invoke-DockerSafe -Arguments (@('compose', '--env-file', $envFile) + $Arguments)
}

function Get-HttpCheck([string] $Url) {
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri $Url -TimeoutSec 10 -ErrorAction Stop
        return [pscustomobject]@{ Success = ([int] $response.StatusCode -eq 200); StatusCode = [int] $response.StatusCode; ContentType = [string] $response.Headers['Content-Type']; Content = [string] $response.Content; Error = '' }
    } catch {
        $statusCode = ''
        try { if ($null -ne $_.Exception.Response) { $statusCode = [int] $_.Exception.Response.StatusCode } } catch { }
        return [pscustomobject]@{ Success = $false; StatusCode = $statusCode; ContentType = ''; Content = ''; Error = (Get-ShortText $_.Exception.Message) }
    }
}

function Get-HttpStatusText($Check) {
    if ($null -ne $Check.StatusCode -and -not [string]::IsNullOrWhiteSpace([string] $Check.StatusCode)) { return [string] $Check.StatusCode }
    return "ERROR $(Get-ShortText $Check.Error)"
}

function Wait-Http200Until([string] $Url, [string] $Label, [datetime] $Deadline) {
    $attempt = 0
    while ((Get-Date) -lt $Deadline) {
        $attempt++
        $check = Get-HttpCheck $Url
        if ($check.Success) { return $check }
        if ($attempt -eq 1 -or $attempt % 5 -eq 0) { Write-Host "Waiting for $Label... $([int] ((Get-Date) - $script:readinessStartedAt).TotalSeconds)s, HTTP $(Get-HttpStatusText $check)" }
        Start-Sleep -Seconds 2
    }
    throw "$Label did not return HTTP 200 within the five-minute readiness window: $Url"
}

function Get-ContainerState([string] $Id) {
    if ([string]::IsNullOrWhiteSpace($Id)) { return '' }
    $result = Invoke-DockerSafe -Arguments @('inspect', '--format', '{{.State.Status}}', $Id)
    if ($result.ExitCode -ne 0) { return '' }
    return $result.Text.Trim()
}

function Get-NginxId {
    $query = Invoke-ComposeSafe @('ps', '-q', 'nginx')
    if ($query.ExitCode -ne 0) { throw "Could not query the nginx service: $(Get-ShortText $query.Text)" }
    $id = $query.Text.Trim()
    if ([string]::IsNullOrWhiteSpace($id)) { throw 'The nginx service is not running. Start the local server first.' }
    return $id
}

function Get-AppId {
    $query = Invoke-ComposeSafe @('ps', '-q', 'app')
    if ($query.ExitCode -ne 0) { throw "Could not query the app service: $(Get-ShortText $query.Text)" }
    $id = $query.Text.Trim()
    if ([string]::IsNullOrWhiteSpace($id)) { throw 'The app service is not running. Start the local server first.' }
    return $id
}

function Get-AppBootstrapHash([string] $AppContainerId) {
    $hashResult = Invoke-DockerSafe -Arguments @('exec', $AppContainerId, 'sha256sum', '/var/www/html/bootstrap/app.php')
    if ($hashResult.ExitCode -ne 0) { throw "Could not calculate the running app bootstrap SHA256: $(Get-ShortText $hashResult.Text)" }
    $hashMatch = [regex]::Match($hashResult.Text, '(?i)\b([0-9a-f]{64})\b')
    if (-not $hashMatch.Success) { throw 'The running app bootstrap SHA256 output was not readable.' }
    return $hashMatch.Groups[1].Value.ToLowerInvariant()
}

function Wait-AppReady([string] $AppContainerId, [string] $LocalHealthUrl) {
    for ($attempt = 1; $attempt -le 60; $attempt++) {
        $stateResult = Invoke-DockerSafe -Arguments @('inspect', '--format', '{{.State.Status}}', $AppContainerId)
        $health = Get-HttpCheck $LocalHealthUrl
        $state = $stateResult.Text.Trim()
        Write-Diagnostic "App readiness attempt $attempt/60: container_state=$state; local /up HTTP=$(Get-HttpStatusText $health)"
        if ($stateResult.ExitCode -eq 0 -and $state -eq 'running' -and $health.Success) { return }
        if ($attempt -eq 1 -or $attempt % 10 -eq 0) { Write-Host "Waiting for app readiness... attempt $attempt/60, container state '$state', local /up HTTP $(Get-HttpStatusText $health)" }
        Start-Sleep -Seconds 2
    }
    throw 'Recreated app did not become ready within 120 seconds.'
}

function Get-NginxConfigHash([string] $NginxContainerId) {
    $hashResult = Invoke-DockerSafe -Arguments @('exec', $NginxContainerId, 'sha256sum', '/etc/nginx/conf.d/default.conf')
    if ($hashResult.ExitCode -ne 0) { throw "Could not calculate the running nginx configuration SHA256: $(Get-ShortText $hashResult.Text)" }
    $hashMatch = [regex]::Match($hashResult.Text, '(?i)\b([0-9a-f]{64})\b')
    if (-not $hashMatch.Success) { throw 'The running nginx configuration SHA256 output was not readable.' }
    return $hashMatch.Groups[1].Value.ToLowerInvariant()
}

function Wait-NginxReady([string] $NginxContainerId, [string] $LocalHealthUrl) {
    for ($attempt = 1; $attempt -le 60; $attempt++) {
        $stateResult = Invoke-DockerSafe -Arguments @('inspect', '--format', '{{.State.Status}}', $NginxContainerId)
        $health = Get-HttpCheck $LocalHealthUrl
        if ($stateResult.ExitCode -eq 0 -and $stateResult.Text.Trim() -eq 'running' -and $health.Success) { return }
        if ($attempt -eq 1 -or $attempt % 10 -eq 0) { Write-Host "Waiting for nginx readiness... attempt $attempt/60, local /up HTTP $(Get-HttpStatusText $health)" }
        Start-Sleep -Seconds 2
    }
    throw 'Recreated nginx did not become ready within 120 seconds.'
}

function Get-EdgeNetwork([string] $NginxContainerId) {
    $result = Invoke-DockerSafe -Arguments @('inspect', '--format', '{{json .NetworkSettings.Networks}}', $NginxContainerId)
    if ($result.ExitCode -ne 0 -or [string]::IsNullOrWhiteSpace($result.Text)) { throw 'Could not inspect the nginx Docker networks.' }
    $networks = $result.Text | ConvertFrom-Json
    $network = ($networks.PSObject.Properties | Where-Object { $_.Name -match '(^|_)edge$' } | Select-Object -First 1).Name
    if ([string]::IsNullOrWhiteSpace($network)) { throw 'Could not find the existing Docker edge network.' }
    return $network
}

function Get-DnsProbe([string] $Hostname, [string] $Server) {
    if (-not (Get-Command Resolve-DnsName -ErrorAction SilentlyContinue)) { return [pscustomobject]@{ Success = $false; Text = 'Resolve-DnsName is not available in this PowerShell environment.' } }
    try {
        if ([string]::IsNullOrWhiteSpace($Server)) { $records = Resolve-DnsName -Name $Hostname -Type A -ErrorAction Stop } else { $records = Resolve-DnsName -Name $Hostname -Type A -Server $Server -ErrorAction Stop }
        return [pscustomobject]@{ Success = $true; Text = (($records | Select-Object Name, Type, IPAddress | Out-String).Trim()) }
    } catch { return [pscustomobject]@{ Success = $false; Text = (Get-ShortText $_.Exception.Message) } }
}

function Get-DnsReadiness([string] $Hostname) {
    $defaultProbe = Get-DnsProbe $Hostname ''
    $publicProbe = Get-DnsProbe $Hostname '1.1.1.1'
    return [pscustomobject]@{ Ready = $defaultProbe.Success; Default = $defaultProbe; Public = $publicProbe }
}

function Collect-FailureDiagnostics {
    if (-not [string]::IsNullOrWhiteSpace($containerId)) {
        Write-DiagnosticBlock 'cloudflared Quick Tunnel logs' (Invoke-DockerSafe -Arguments @('logs', '--tail=200', $containerId)).Text
        Write-DiagnosticBlock 'cloudflared container state' (Invoke-DockerSafe -Arguments @('inspect', '--format', '{{.State.Status}}', $containerId)).Text
    }
    if (-not [string]::IsNullOrWhiteSpace($edgeNetwork)) { Write-DiagnosticBlock "Docker network inspect: $edgeNetwork" (Invoke-DockerSafe -Arguments @('network', 'inspect', $edgeNetwork)).Text }
    if (-not [string]::IsNullOrWhiteSpace($appId)) {
        Write-DiagnosticBlock 'app service status' (Invoke-ComposeSafe @('ps', 'app')).Text
        Write-DiagnosticBlock 'app logs' (Invoke-ComposeSafe @('logs', '--tail=120', 'app')).Text
    }
    Write-DiagnosticBlock 'nginx service status' (Invoke-ComposeSafe @('ps', 'nginx')).Text
    Write-DiagnosticBlock 'nginx logs' (Invoke-ComposeSafe @('logs', '--tail=120', 'nginx')).Text
}

Remove-PublicDemoUrlFile
$diagnosticHeader = @(
    'PUBLIC DEMO DIAGNOSTIC'
    "Started: $((Get-Date).ToString('yyyy-MM-dd HH:mm:ss zzz'))"
    'Tunnel mode: Cloudflare Quick Tunnel / TryCloudflare'
    'Laravel runtime mutation: NONE'
    'Laravel containers/services are not recreated by this flow.'
) -join [Environment]::NewLine
Write-Utf8NoBom $diagnosticFile ($diagnosticHeader + [Environment]::NewLine)

$port = Get-EnvValue 'HTTP_PORT'
if ([string]::IsNullOrWhiteSpace($port)) { $port = '8080' }

try {
    Write-Host '============================================================'
    Write-Host 'STARTING PUBLIC DEMO'
    Write-Host '============================================================'
    Write-Host ''
    Write-Host 'Local server:'
    Write-Host "http://localhost:$port"
    Write-Host ''
    Write-Host 'Starting Cloudflare Quick Tunnel...'
    Write-Host 'Waiting for public HTTPS address...'
    Write-Host ''

    $localHealth = Get-HttpCheck "http://localhost:$port/up"
    Write-Diagnostic ("Local /up before tunnel: HTTP={0}; response/error={1}" -f (Get-HttpStatusText $localHealth), (Get-ShortText $(if ($localHealth.Success) { $localHealth.Content } else { $localHealth.Error })))
    if (-not $localHealth.Success) { throw "Local application /up did not return HTTP 200: $(Get-HttpStatusText $localHealth)" }
    $localLogin = Get-HttpCheck "http://localhost:$port/login"
    Write-Diagnostic ("Local /login before tunnel: HTTP={0}; response/error={1}" -f (Get-HttpStatusText $localLogin), (Get-ShortText $(if ($localLogin.Success) { $localLogin.Content } else { $localLogin.Error })))
    if (-not $localLogin.Success) { throw "Local application /login did not return HTTP 200: $(Get-HttpStatusText $localLogin)" }

    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Docker is not installed. Start Docker Desktop and try again.' }
    $dockerVersion = Invoke-DockerSafe -Arguments @('version', '--format', '{{.Server.Version}}')
    Write-DiagnosticBlock 'Docker Engine availability' $dockerVersion.Text
    if ($dockerVersion.ExitCode -ne 0) { throw 'Docker is not running. Start Docker Desktop and try again.' }

    if ((Invoke-DockerSafe -Arguments @('image', 'inspect', $cloudflaredImage)).ExitCode -ne 0) {
        Write-Host "Downloading $cloudflaredImage..."
        $imagePull = Invoke-DockerSafe -Arguments @('pull', $cloudflaredImage)
        Write-DiagnosticBlock 'cloudflared image pull result' $imagePull.Text
        if ($imagePull.ExitCode -ne 0) { throw "Could not download $cloudflaredImage. Check Docker connectivity and try again." }
    }

    $appId = Get-AppId
    $hostBootstrap = Join-Path $projectRoot 'bootstrap/app.php'
    if (-not (Test-Path -LiteralPath $hostBootstrap)) { throw 'Host bootstrap/app.php was not found.' }
    $hostBootstrapHash = (Get-FileHash -LiteralPath $hostBootstrap -Algorithm SHA256).Hash.ToLowerInvariant()
    $containerBootstrapHash = Get-AppBootstrapHash $appId
    Write-Diagnostic "Host bootstrap/app.php SHA256: $hostBootstrapHash"
    Write-Diagnostic "Running app bootstrap/app.php SHA256: $containerBootstrapHash"
    $appRebuildRequired = $hostBootstrapHash -ne $containerBootstrapHash
    Write-Diagnostic "APP REBUILD REQUIRED: $(if ($appRebuildRequired) { 'YES' } else { 'NO' })"
    if ($appRebuildRequired) {
        Write-Host 'Application bootstrap differs. Rebuilding and recreating app only...'
        $appBuild = Invoke-ComposeSafe @('build', 'app')
        Write-DiagnosticBlock 'app-only build result' $appBuild.Text
        if ($appBuild.ExitCode -ne 0) { throw 'Application image rebuild failed. DB, queue-worker, and scheduler were not changed.' }
        $appRecreate = Invoke-ComposeSafe @('up', '-d', '--force-recreate', '--no-deps', 'app')
        Write-DiagnosticBlock 'app-only recreate result' $appRecreate.Text
        if ($appRecreate.ExitCode -ne 0) { throw 'App-only recreate failed. DB, queue-worker, and scheduler were not changed.' }
        $appId = Get-AppId
        Wait-AppReady $appId "http://localhost:$port/up"
        $containerBootstrapHashAfter = Get-AppBootstrapHash $appId
        Write-Diagnostic "Running app bootstrap/app.php SHA256 after recreate: $containerBootstrapHashAfter"
        if ($hostBootstrapHash -ne $containerBootstrapHashAfter) { throw 'Host and running app bootstrap SHA256 values still differ after app-only recreate.' }
        $localHealthAfterApp = Get-HttpCheck "http://localhost:$port/up"
        $localLoginAfterApp = Get-HttpCheck "http://localhost:$port/login"
        Write-Diagnostic ("Local /up after app-only refresh: HTTP={0}; /login: HTTP={1}" -f (Get-HttpStatusText $localHealthAfterApp), (Get-HttpStatusText $localLoginAfterApp))
        if (-not $localHealthAfterApp.Success -or -not $localLoginAfterApp.Success) { throw 'Local /up or /login failed after app-only refresh.' }
    } else {
        Write-Diagnostic 'App bootstrap hash matches; app was not rebuilt or recreated.'
    }

    $nginxId = Get-NginxId
    $hostNginxConfig = Join-Path $projectRoot 'deployment/nginx/default.conf'
    if (-not (Test-Path -LiteralPath $hostNginxConfig)) { throw 'Host nginx configuration file was not found.' }
    $hostNginxHash = (Get-FileHash -LiteralPath $hostNginxConfig -Algorithm SHA256).Hash.ToLowerInvariant()
    $containerNginxHash = Get-NginxConfigHash $nginxId
    Write-Diagnostic "Host nginx config SHA256: $hostNginxHash"
    Write-Diagnostic "Running nginx config SHA256: $containerNginxHash"
    $nginxRebuildRequired = $hostNginxHash -ne $containerNginxHash
    Write-Diagnostic "NGINX REBUILD REQUIRED: $(if ($nginxRebuildRequired) { 'YES' } else { 'NO' })"
    if ($nginxRebuildRequired) {
        Write-Host 'Nginx configuration differs. Rebuilding and recreating nginx only...'
        $nginxBuild = Invoke-ComposeSafe @('build', 'nginx')
        Write-DiagnosticBlock 'nginx-only build result' $nginxBuild.Text
        if ($nginxBuild.ExitCode -ne 0) { throw 'Nginx image rebuild failed. Other services were not changed.' }
        $nginxRecreate = Invoke-ComposeSafe @('up', '-d', '--force-recreate', '--no-deps', 'nginx')
        Write-DiagnosticBlock 'nginx-only recreate result' $nginxRecreate.Text
        if ($nginxRecreate.ExitCode -ne 0) { throw 'Nginx-only recreate failed. Other services were not changed.' }
        $nginxId = Get-NginxId
        Wait-NginxReady $nginxId "http://localhost:$port/up"
        $nginxTest = Invoke-DockerSafe -Arguments @('exec', $nginxId, 'nginx', '-t')
        Write-DiagnosticBlock 'nginx -t after recreate' $nginxTest.Text
        if ($nginxTest.ExitCode -ne 0) { throw 'Recreated nginx configuration failed nginx -t.' }
        $containerNginxHashAfter = Get-NginxConfigHash $nginxId
        Write-Diagnostic "Running nginx config SHA256 after recreate: $containerNginxHashAfter"
        if ($hostNginxHash -ne $containerNginxHashAfter) { throw 'Host and running nginx configuration SHA256 values still differ after nginx-only recreate.' }
        if (-not (Get-HttpCheck "http://localhost:$port/up").Success -or -not (Get-HttpCheck "http://localhost:$port/login").Success) { throw 'Local /up or /login failed after nginx-only refresh.' }
    } else { Write-Diagnostic 'Nginx configuration hash matches; nginx was not rebuilt or recreated.' }

    $edgeNetwork = Get-EdgeNetwork $nginxId
    Write-Diagnostic "Using nginx container: $nginxId"
    Write-Diagnostic "Using existing Docker edge network: $edgeNetwork"
    $existingQuery = Invoke-DockerSafe -Arguments @('ps', '-aq', '--filter', "name=^/$containerName$")
    if ($existingQuery.ExitCode -ne 0) { throw 'Could not inspect the existing Quick Tunnel container.' }
    $existingIds = @($existingQuery.Text -split '\s+' | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
    foreach ($existingId in $existingIds) {
        $removeExisting = Invoke-DockerSafe -Arguments @('rm', '-f', $existingId)
        Write-DiagnosticBlock "Removed previous public-demo container: $existingId" $removeExisting.Text
        if ($removeExisting.ExitCode -ne 0) { throw 'Could not remove the previous Quick Tunnel container.' }
    }

    $runResult = Invoke-DockerSafe -Arguments @('run', '-d', '--name', $containerName, '--restart', 'unless-stopped', '--label', 'com.pacientu-anketu-sistema.component=public-demo', '--network', $edgeNetwork, $cloudflaredImage, 'tunnel', '--no-autoupdate', '--url', 'http://nginx:80')
    Write-DiagnosticBlock 'Quick Tunnel container creation/start result' $runResult.Text
    if ($runResult.ExitCode -ne 0) { throw 'Could not start the Cloudflare Quick Tunnel container.' }
    $containerId = (($runResult.Text -split '\r?\n' | ForEach-Object { $_.Trim() } | Where-Object { $_ -match '^[0-9a-f]{12,64}$' } | Select-Object -Last 1))
    if ([string]::IsNullOrWhiteSpace($containerId)) { throw 'Quick Tunnel started without a readable container ID.' }

    $script:readinessStartedAt = Get-Date
    $readinessDeadline = $script:readinessStartedAt.AddMinutes(5)
    $logs = ''
    while ((Get-Date) -lt $readinessDeadline) {
        $logsResult = Invoke-DockerSafe -Arguments @('logs', $containerId)
        if ($logsResult.ExitCode -ne 0) { throw 'Could not read the Cloudflare Quick Tunnel container logs.' }
        $logs = $logsResult.Text
        $urlMatch = [regex]::Match($logs, 'https://[a-z0-9-]+\.trycloudflare\.com')
        if ($urlMatch.Success) {
            $publicUrl = $urlMatch.Value.TrimEnd('/')
            Save-PublicDemoUrlFile 'URL FOUND - waiting for Registered tunnel connection'
            Write-Diagnostic "Found trycloudflare.com URL immediately: $publicUrl"
            Write-Host "Cloudflare URL found: $publicUrl"
            break
        }
        if ((Get-ContainerState $containerId) -ne 'running') { throw 'The Quick Tunnel container stopped before publishing a public URL.' }
        $elapsed = [int] ((Get-Date) - $script:readinessStartedAt).TotalSeconds
        if ($elapsed -eq 0 -or $elapsed % 5 -eq 0) { Write-Host "Waiting for Cloudflare URL... ${elapsed}s" }
        Start-Sleep -Seconds 2
    }
    if ([string]::IsNullOrWhiteSpace($publicUrl)) { throw 'Quick Tunnel did not publish a public URL within the five-minute readiness window.' }

    $registered = $false
    while ((Get-Date) -lt $readinessDeadline) {
        $logsResult = Invoke-DockerSafe -Arguments @('logs', $containerId)
        if ($logsResult.ExitCode -ne 0) { throw 'Could not read the Cloudflare Quick Tunnel container logs.' }
        $logs = $logsResult.Text
        if ($logs -match '(?i)Registered tunnel connection') { $registered = $true; break }
        if ((Get-ContainerState $containerId) -ne 'running') { throw 'The Quick Tunnel container stopped before registering a connection.' }
        $elapsed = [int] ((Get-Date) - $script:readinessStartedAt).TotalSeconds
        if ($elapsed -eq 0 -or $elapsed % 5 -eq 0) { Write-Host "Waiting for Registered tunnel connection... ${elapsed}s" }
        Start-Sleep -Seconds 2
    }
    if (-not $registered) { throw 'Quick Tunnel did not register a connection within the five-minute readiness window.' }
    Write-DiagnosticBlock 'cloudflared logs after registration' $logs

    $hostname = ([Uri] $publicUrl).Host
    $dnsReady = $false
    $lastDnsReadiness = $null
    $lastDnsLogAt = [datetime]::MinValue
    while ((Get-Date) -lt $readinessDeadline) {
        $lastDnsReadiness = Get-DnsReadiness $hostname
        if ($lastDnsReadiness.Ready) { $dnsReady = $true; break }
        $now = Get-Date
        if (($now - $lastDnsLogAt).TotalSeconds -ge 10) {
            Write-DiagnosticBlock "DNS readiness for $hostname (Windows default resolver)" $lastDnsReadiness.Default.Text
            Write-DiagnosticBlock "DNS readiness for $hostname (public resolver 1.1.1.1)" $lastDnsReadiness.Public.Text
            $lastDnsLogAt = $now
        }
        $elapsed = [int] ($now - $script:readinessStartedAt).TotalSeconds
        if ($elapsed -eq 0 -or $elapsed % 5 -eq 0) { Write-Host "Waiting for DNS readiness... ${elapsed}s" }
        Start-Sleep -Seconds 2
    }
    if (-not $dnsReady) {
        if ($null -ne $lastDnsReadiness) {
            Write-DiagnosticBlock "FINAL DNS result for $hostname (Windows default resolver)" $lastDnsReadiness.Default.Text
            Write-DiagnosticBlock "FINAL DNS result for $hostname (public resolver 1.1.1.1)" $lastDnsReadiness.Public.Text
        }
        Save-PublicDemoUrlFile 'DNS NOT READY YET'
        Write-Diagnostic 'FINAL: DNS NOT READY YET; Quick Tunnel container intentionally left running.'
        Write-Host ''
        Write-Host '============================================================'
        Write-Host 'PUBLIC DEMO DNS NOT READY YET' -ForegroundColor Yellow
        Write-Host '============================================================'
        Write-Host ''
        Write-Host 'Public URL:'
        Write-Host $publicUrl
        Write-Host 'DNS NOT READY YET - the Quick Tunnel remains running.'
        Write-Host "Diagnostics saved to: $diagnosticDisplay"
        exit 0
    }
    if ($null -ne $lastDnsReadiness) {
        Write-DiagnosticBlock "FINAL DNS result for $hostname (Windows default resolver)" $lastDnsReadiness.Default.Text
        Write-DiagnosticBlock "FINAL DNS result for $hostname (public resolver 1.1.1.1)" $lastDnsReadiness.Public.Text
    }

    $publicHealth = Wait-Http200Until "$publicUrl/up" 'public /up' $readinessDeadline
    $login = Wait-Http200Until "$publicUrl/login" 'public /login' $readinessDeadline
    Write-Diagnostic ("Public /up HTTP={0}; public /login HTTP={1}" -f (Get-HttpStatusText $publicHealth), (Get-HttpStatusText $login))
    $loginHtml = [string] $login.Content
    $insecureHtmlUrls = @([regex]::Matches($loginHtml, '(?i)http://[^"\s<>]+') | ForEach-Object { $_.Value } | Select-Object -Unique)
    Write-Diagnostic "Public login insecure HTTP URLs: $($insecureHtmlUrls -join ', ')"
    if ($insecureHtmlUrls.Count -gt 0) { throw 'Public login HTML contains insecure http:// URL(s); mixed content is not allowed.' }
    $formActions = @([regex]::Matches($loginHtml, '(?is)<form\b[^>]*\baction="([^"]+)"') | ForEach-Object { $_.Groups[1].Value })
    if ($formActions | Where-Object { $_ -match '^http://' }) { throw 'Public login form action uses insecure http://.' }
    $cssMatch = [regex]::Match($loginHtml, '(?i)(?:href|src)="([^"\s]+/build/[^"\s]+\.css(?:\?[^"\s]*)?)"')
    $jsMatch = [regex]::Match($loginHtml, '(?i)(?:href|src)="([^"\s]+/build/[^"\s]+\.js(?:\?[^"\s]*)?)"')
    if (-not $cssMatch.Success -or -not $jsMatch.Success) { throw 'Public login page did not expose both CSS and JS asset URLs.' }
    $cssPath = $cssMatch.Groups[1].Value
    $cssUrl = if ($cssPath -match '^https?://') { $cssPath } else { "$publicUrl/$($cssPath.TrimStart('/'))" }
    $jsPath = $jsMatch.Groups[1].Value
    $jsUrl = if ($jsPath -match '^https?://') { $jsPath } else { "$publicUrl/$($jsPath.TrimStart('/'))" }
    Write-Diagnostic "Generated CSS URL: $cssUrl"
    Write-Diagnostic "Generated JS URL: $jsUrl"
    if ($cssUrl -notmatch '^https://' -or -not $cssUrl.StartsWith("$publicUrl/") -or $jsUrl -notmatch '^https://' -or -not $jsUrl.StartsWith("$publicUrl/")) { throw 'Public CSS/JS URL is not HTTPS/current-host scoped.' }
    $cssCheck = Get-HttpCheck $cssUrl
    $jsCheck = Get-HttpCheck $jsUrl
    Write-Diagnostic ("CSS HTTP={0}; Content-Type={1}; JS HTTP={2}; Content-Type={3}" -f (Get-HttpStatusText $cssCheck), $cssCheck.ContentType, (Get-HttpStatusText $jsCheck), $jsCheck.ContentType)
    if (-not $cssCheck.Success -or $cssCheck.ContentType -notmatch '(?i)text/css') { throw "Public CSS asset check failed: $(Get-HttpStatusText $cssCheck)" }
    if (-not $jsCheck.Success -or $jsCheck.ContentType -notmatch '(?i)(javascript|ecmascript)') { throw "Public JS asset check failed: $(Get-HttpStatusText $jsCheck)" }

    Save-PublicDemoUrlFile 'READY'
    Write-Diagnostic 'FINAL: READY'
    Write-Host ''
    Write-Host '============================================================'
    Write-Host 'PUBLIC DEMO READY' -ForegroundColor Green
    Write-Host '============================================================'
    Write-Host ''
    Write-Host 'Public URL:'
    Write-Host $publicUrl
    Write-Host 'Login:'
    Write-Host "$publicUrl/login"
    Write-Host 'Local URL:'
    Write-Host "http://localhost:$port"
    Write-Host "URL saved to: $publicDemoUrlDisplay"
    Write-Host "Diagnostics: $diagnosticDisplay"
}
catch {
    $failureMessage = $_.Exception.Message
    try {
        Write-DiagnosticBlock 'FINAL: FAILED' $failureMessage
        Collect-FailureDiagnostics
        Remove-PublicDemoUrlFile
        if (-not [string]::IsNullOrWhiteSpace($containerId)) {
            Write-DiagnosticBlock 'Removed failed Quick Tunnel container' (Invoke-DockerSafe -Arguments @('rm', '-f', $containerId)).Text
        }
    } catch { }
    Write-Host ''
    Write-Host '============================================================'
    Write-Host 'PUBLIC DEMO FAILED' -ForegroundColor Red
    Write-Host '============================================================'
    Write-Host $failureMessage -ForegroundColor Red
    Write-Host "Diagnostics saved to: $diagnosticDisplay"
    exit 1
}
