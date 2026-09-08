$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $projectRoot
$envFile = Join-Path $projectRoot '.env.production'
$backupParent = Join-Path $PSScriptRoot 'backups'
$restoreTimestamp = ''
$selectedBackupRoot = ''
$dbId = ''
$appId = ''
$dumpName = ''
$previousAppKey = ''
$appKeyChanged = $false
$servicesStopAttempted = $false
$servicesStopped = $false
$rollbackMessages = @()

function Get-ShortText([string] $Text) {
    if ([string]::IsNullOrWhiteSpace($Text)) { return '' }
    $short = ($Text -replace '\s+', ' ').Trim()
    if ($short.Length -gt 300) { return $short.Substring(0, 300) + '...' }
    return $short
}

function Read-Utf8NoBom([string] $Path) {
    $encoding = New-Object System.Text.UTF8Encoding($false)
    return [System.IO.File]::ReadAllText($Path, $encoding)
}

function Get-EnvValueFromFile([string] $Path, [string] $Key) {
    $content = Read-Utf8NoBom $Path
    $line = [regex]::Match($content, "(?m)^$([regex]::Escape($Key))=([^\r\n]*)")
    if (-not $line.Success) { return '' }
    return $line.Groups[1].Value.Trim().Trim('"')
}

function Set-EnvValue([string] $Path, [string] $Key, [string] $Value) {
    $content = Read-Utf8NoBom $Path
    $lines = $content -split "`r?`n", -1
    $found = $false
    $keyPattern = "^$([regex]::Escape($Key))="
    for ($index = 0; $index -lt $lines.Count; $index++) {
        if ($lines[$index] -match $keyPattern) {
            $lines[$index] = "$Key=$Value"
            $found = $true
        }
    }
    if (-not $found) { $lines += "$Key=$Value" }
    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, ($lines -join [Environment]::NewLine), $encoding)
}

function Get-ContainerEnvValue([string] $ContainerId, [string] $Key) {
    $result = Invoke-DockerSafe @('inspect', '--format', '{{range .Config.Env}}{{println .}}{{end}}', $ContainerId)
    if ($result.ExitCode -ne 0) { throw "Could not inspect the database container environment: $(Get-ShortText $result.Text)" }
    foreach ($line in ($result.Text -split '\r?\n')) {
        if ($line.StartsWith("$Key=", [System.StringComparison]::Ordinal)) { return $line.Substring($Key.Length + 1) }
    }
    return ''
}

function New-DatabaseResetSql([string] $DatabaseName, [string] $Path) {
    if ([string]::IsNullOrWhiteSpace($DatabaseName)) { throw 'MARIADB_DATABASE is empty in the running database container.' }
    if ($DatabaseName -notmatch '^[A-Za-z0-9_]+$') { throw 'MARIADB_DATABASE contains unsupported characters for a safe restore.' }
    $sql = "DROP DATABASE IF EXISTS ``$DatabaseName``;`r`nCREATE DATABASE ``$DatabaseName``;`r`n"
    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $sql, $encoding)
}

function Invoke-DockerSafe {
    param([Parameter(Mandatory = $true)][string[]] $Arguments)
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = & docker @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous }
    return [pscustomobject]@{
        ExitCode = if ($null -eq $exitCode) { 1 } else { [int] $exitCode }
        Text = (@($output | ForEach-Object { [string] $_ }) -join [Environment]::NewLine)
    }
}

function Invoke-ComposeSafe([string[]] $Arguments) {
    return Invoke-DockerSafe -Arguments (@('compose', '--env-file', $envFile) + $Arguments)
}

function Invoke-ComposeOrThrow([string[]] $Arguments, [string] $FailureMessage) {
    $result = Invoke-ComposeSafe $Arguments
    if ($result.ExitCode -ne 0) { throw "$FailureMessage $(Get-ShortText $result.Text)" }
    return $result
}

function Get-ComposeContainerId([string] $Service) {
    $result = Invoke-ComposeSafe @('ps', '-aq', $Service)
    if ($result.ExitCode -ne 0) { throw "Could not inspect the $Service container: $(Get-ShortText $result.Text)" }
    $id = ($result.Text -split '\s+' | Where-Object { -not [string]::IsNullOrWhiteSpace($_) } | Select-Object -First 1)
    if ([string]::IsNullOrWhiteSpace($id)) { throw "The $Service container does not exist. Run INSTALL-SERVER.bat first." }
    return $id.Trim()
}

function Get-ContainerState([string] $Id) {
    $result = Invoke-DockerSafe @('inspect', '--format', '{{.State.Status}}', $Id)
    if ($result.ExitCode -ne 0) { throw "Could not inspect container state: $(Get-ShortText $result.Text)" }
    return $result.Text.Trim()
}

function Assert-Running([string] $Service) {
    $id = Get-ComposeContainerId $Service
    if ((Get-ContainerState $id) -ne 'running') { throw "$Service container is not running after restore." }
    return $id
}

function Invoke-StopPublicDemo {
    $stopScript = Join-Path $PSScriptRoot 'STOP-PUBLIC-DEMO.ps1'
    if (-not (Test-Path -LiteralPath $stopScript)) { throw 'STOP-PUBLIC-DEMO.ps1 was not found.' }
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $stopScript
    if ($LASTEXITCODE -ne 0) { throw 'Could not stop the public demo tunnel and restore its temporary DNS state.' }
}

function Restore-Database([string] $DatabaseContainerId, [string] $DumpPath) {
    $dumpNameLocal = "restore-$((Get-Date).ToString('yyyyMMddHHmmss')).sql"
    $resetNameLocal = "restore-reset-$((Get-Date).ToString('yyyyMMddHHmmss')).sql"
    $resetPathLocal = Join-Path ([System.IO.Path]::GetTempPath()) $resetNameLocal
    $databaseName = Get-ContainerEnvValue $DatabaseContainerId 'MARIADB_DATABASE'
    New-DatabaseResetSql $databaseName $resetPathLocal
    try {
        & docker cp $DumpPath "${DatabaseContainerId}:/tmp/$dumpNameLocal" *> $null
        if ($LASTEXITCODE -ne 0) { throw 'Could not copy database.sql into the running MariaDB container.' }
        & docker cp $resetPathLocal "${DatabaseContainerId}:/tmp/$resetNameLocal" *> $null
        if ($LASTEXITCODE -ne 0) { throw 'Could not copy the database reset SQL into the running MariaDB container.' }
        $restoreCommand = 'MYSQL_PWD=$MARIADB_ROOT_PASSWORD mariadb -uroot < /tmp/' + $resetNameLocal + ' && MYSQL_PWD=$MARIADB_ROOT_PASSWORD mariadb -uroot < /tmp/' + $dumpNameLocal
        $result = Invoke-DockerSafe @('exec', '-i', $DatabaseContainerId, 'sh', '-ec', $restoreCommand)
        if ($result.ExitCode -ne 0) { throw "Database restore failed: $(Get-ShortText $result.Text)" }
    } finally {
        Invoke-DockerSafe @('exec', $DatabaseContainerId, 'rm', '-f', "/tmp/$dumpNameLocal") | Out-Null
        Invoke-DockerSafe @('exec', $DatabaseContainerId, 'rm', '-f', "/tmp/$resetNameLocal") | Out-Null
        Remove-Item -LiteralPath $resetPathLocal -Force -ErrorAction SilentlyContinue
    }
}

function Restore-PrivateStorage([string] $AppContainerId, [string] $BackupPrivateRoot) {
    $imageResult = Invoke-DockerSafe @('inspect', '--format', '{{.Config.Image}}', $AppContainerId)
    if ($imageResult.ExitCode -ne 0 -or [string]::IsNullOrWhiteSpace($imageResult.Text)) { throw 'Could not determine the app image for private storage restore.' }
    $appImage = $imageResult.Text.Trim()
    $clearCommand = 'find /var/www/html/storage/app/private -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + && chown -R www-data:www-data /var/www/html/storage/app/private && chmod -R ug+rwX /var/www/html/storage/app/private'
    $clearResult = Invoke-DockerSafe @('run', '--rm', '--volumes-from', $AppContainerId, '--entrypoint', 'sh', $appImage, '-ec', $clearCommand)
    if ($clearResult.ExitCode -ne 0) { throw "Could not clear the current private storage volume: $(Get-ShortText $clearResult.Text)" }

    $source = Join-Path $BackupPrivateRoot '.'
    & docker cp $source "${AppContainerId}:/var/www/html/storage/app/private/" *> $null
    if ($LASTEXITCODE -ne 0) { throw 'Could not restore storage-app-private.' }
    $permissionResult = Invoke-DockerSafe @('run', '--rm', '--volumes-from', $AppContainerId, '--entrypoint', 'sh', $appImage, '-ec', 'chown -R www-data:www-data /var/www/html/storage/app/private && chmod -R ug+rwX /var/www/html/storage/app/private')
    if ($permissionResult.ExitCode -ne 0) { throw 'Could not apply www-data permissions to private storage.' }
}

function Write-Failure([string] $Message) {
    Write-Host ''
    Write-Host '============================================================'
    Write-Host 'RESTORE FAILED' -ForegroundColor Red
    Write-Host '============================================================'
    Write-Host $Message -ForegroundColor Red
    if (-not [string]::IsNullOrWhiteSpace($selectedBackupRoot)) { Write-Host "Backup was not modified: $selectedBackupRoot" }
}

try {
    if (-not (Test-Path -LiteralPath $envFile)) { throw 'Missing .env.production. Run INSTALL-SERVER.bat first.' }
    if (-not (Test-Path -LiteralPath $backupParent)) { throw 'No deployment/backups directory exists.' }

    $backups = @(Get-ChildItem -LiteralPath $backupParent -Directory | Sort-Object Name -Descending)
    if ($backups.Count -eq 0) { throw 'No timestamped backups are available.' }
    Write-Host 'Available backups:'
    for ($index = 0; $index -lt $backups.Count; $index++) {
        Write-Host ("[{0}] {1}" -f ($index + 1), $backups[$index].Name)
    }
    $selection = 0
    $selectionText = Read-Host 'Choose a backup number'
    if (-not [int]::TryParse($selectionText, [ref] $selection) -or $selection -lt 1 -or $selection -gt $backups.Count) { throw 'Invalid backup selection.' }
    $selectedBackup = $backups[$selection - 1]
    $selectedBackupRoot = $selectedBackup.FullName
    $restoreTimestamp = $selectedBackup.Name

    $dumpPath = Join-Path $selectedBackupRoot 'database.sql'
    $privateRoot = Join-Path $selectedBackupRoot 'storage-app-private'
    $backupEnvPath = Join-Path $selectedBackupRoot 'deployment-config\.env.production'
    foreach ($requiredPath in @($dumpPath, $privateRoot, $backupEnvPath)) {
        if (-not (Test-Path -LiteralPath $requiredPath)) { throw "Selected backup is incomplete: $requiredPath" }
    }
    if (-not (Get-Item -LiteralPath $dumpPath).PSIsContainer -and (Get-Item -LiteralPath $dumpPath).Length -gt 0) { } else { throw 'Selected database.sql is empty.' }
    if (-not (Get-Item -LiteralPath $privateRoot).PSIsContainer) { throw 'Selected storage-app-private is not a directory.' }
    $backupAppKey = Get-EnvValueFromFile $backupEnvPath 'APP_KEY'
    if ([string]::IsNullOrWhiteSpace($backupAppKey)) { throw 'Selected backup does not contain a usable APP_KEY.' }

    Write-Host ''
    Write-Host "Selected backup: $restoreTimestamp"
    Write-Host 'This will replace the current application database and private storage.' -ForegroundColor Yellow
    $confirmation = Read-Host 'Type RESTORE to continue'
    if ($confirmation -cne 'RESTORE') { throw 'Restore cancelled. No application data was changed.' }

    $dockerVersion = Invoke-DockerSafe @('version', '--format', '{{.Server.Version}}')
    if ($dockerVersion.ExitCode -ne 0) { throw 'Docker is not running.' }
    $dbId = Get-ComposeContainerId 'db'
    $appId = Get-ComposeContainerId 'app'
    if ((Get-ContainerState $dbId) -ne 'running') { throw 'The database container must be running before restore.' }
    if ((Get-ContainerState $appId) -eq '') { throw 'Could not inspect the app container.' }

    $env:DEPLOY_ENV_FILE = '.env.production'
    $previousAppKey = Get-EnvValueFromFile $envFile 'APP_KEY'
    Write-Host 'Stopping public demo...'
    Invoke-StopPublicDemo
    Write-Host 'Stopping app services while keeping MariaDB running...'
    $servicesStopAttempted = $true
    Invoke-ComposeOrThrow @('stop', 'nginx', 'app', 'queue-worker', 'scheduler') 'Could not stop application services.' | Out-Null
    $servicesStopped = $true

    Write-Host 'Restoring APP_KEY only...'
    Set-EnvValue $envFile 'APP_KEY' $backupAppKey
    $appKeyChanged = $true

    Write-Host 'Restoring database snapshot...'
    Restore-Database $dbId $dumpPath

    Write-Host 'Restoring private application storage...'
    Restore-PrivateStorage $appId $privateRoot

    Write-Host 'Applying current migrations to the restored database...'
    Invoke-ComposeOrThrow @('run', '--rm', '--no-deps', 'app', 'php', 'artisan', 'migrate', '--force') 'Could not run migrations after database restore.' | Out-Null

    Write-Host 'Starting application services...'
    Invoke-ComposeOrThrow @('up', '-d', '--force-recreate', '--no-deps', 'app', 'nginx', 'queue-worker', 'scheduler') 'Could not start application services with the current environment.' | Out-Null
    Invoke-ComposeOrThrow @('exec', '-T', 'app', 'php', 'artisan', 'config:cache') 'Could not rebuild Laravel config cache.' | Out-Null
    Invoke-ComposeOrThrow @('exec', '-T', 'app', 'php', 'artisan', 'view:cache') 'Could not rebuild Laravel view cache.' | Out-Null
    Invoke-ComposeOrThrow @('restart', 'app', 'queue-worker', 'scheduler', 'nginx') 'Could not restart application services with restored configuration.' | Out-Null

    Assert-Running 'db' | Out-Null
    Assert-Running 'app' | Out-Null
    Assert-Running 'nginx' | Out-Null
    Assert-Running 'queue-worker' | Out-Null
    Assert-Running 'scheduler' | Out-Null
    $port = Get-EnvValueFromFile $envFile 'HTTP_PORT'
    if ([string]::IsNullOrWhiteSpace($port)) { $port = '8080' }
    $health = Invoke-WebRequest -UseBasicParsing -Uri "http://localhost:$port/up" -TimeoutSec 20 -ErrorAction Stop
    if ([int] $health.StatusCode -ne 200) { throw "Health check failed with HTTP $($health.StatusCode)." }

    Write-Host ''
    Write-Host '============================================================'
    Write-Host 'RESTORE COMPLETE' -ForegroundColor Green
    Write-Host '============================================================'
    Write-Host "Restore source: $restoreTimestamp"
    Write-Host "Health URL: http://localhost:$port/up"
}
catch {
    $message = $_.Exception.Message
    try {
        if ($appKeyChanged -and (Test-Path -LiteralPath $envFile)) {
            Set-EnvValue $envFile 'APP_KEY' $previousAppKey
            $rollbackMessages += 'APP_KEY rollback: PASS'
        }
    } catch {
        $rollbackMessages += "APP_KEY rollback: FAILED ($(Get-ShortText $_.Exception.Message))"
    }
    try {
        if ($servicesStopAttempted) {
            $recovery = Invoke-ComposeSafe @('up', '-d', 'app', 'nginx', 'queue-worker', 'scheduler')
            if ($recovery.ExitCode -ne 0) {
                $rollbackMessages += "Local services recovery: FAILED ($(Get-ShortText $recovery.Text))"
            } else {
                $rollbackMessages += 'Local services recovery: PASS'
                $cache = Invoke-ComposeSafe @('exec', '-T', 'app', 'php', 'artisan', 'config:cache')
                $views = Invoke-ComposeSafe @('exec', '-T', 'app', 'php', 'artisan', 'view:cache')
                if ($cache.ExitCode -eq 0 -and $views.ExitCode -eq 0) {
                    $rollbackMessages += 'Local cache recovery: PASS'
                } else {
                    $rollbackMessages += 'Local cache recovery: FAILED'
                }
            }
        }
    } catch { }
    Write-Failure $message
    foreach ($rollbackMessage in $rollbackMessages) { Write-Host $rollbackMessage -ForegroundColor Yellow }
    exit 1
}
