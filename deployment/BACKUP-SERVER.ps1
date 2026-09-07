$ErrorActionPreference = 'Stop'
trap {
    Write-Host ''
    Write-Host 'BACKUP FAILED' -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}

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

$env:DEPLOY_ENV_FILE = '.env.production'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $PSScriptRoot (Join-Path 'backups' $stamp)
$privateRoot = Join-Path $backupRoot 'storage-app-private'
$configRoot = Join-Path $backupRoot 'deployment-config'
New-Item -ItemType Directory -Force -Path $privateRoot, $configRoot | Out-Null

$dumpPath = Join-Path $backupRoot 'database.sql'
$dumpName = "backup-$stamp.sql"
$dumpCommand = 'if [ -z "$MARIADB_DATABASE" ] || [ -z "$MARIADB_USER" ] || [ -z "$MARIADB_PASSWORD" ]; then exit 2; fi; MYSQL_PWD="$MARIADB_PASSWORD" mariadb-dump --single-transaction --routines --events --no-tablespaces --hex-blob -u"$MARIADB_USER" --databases "$MARIADB_DATABASE" > /tmp/' + $dumpName
$dbContainerId = (& docker compose --env-file $envFile ps -q db).Trim()
if ([string]::IsNullOrWhiteSpace($dbContainerId)) { throw 'The database container is not running.' }
try {
    & docker compose --env-file $envFile exec -T db sh -c $dumpCommand
    if ($LASTEXITCODE -ne 0) { throw 'MariaDB dump failed.' }
    & docker cp "${dbContainerId}:/tmp/$dumpName" $dumpPath
    if ($LASTEXITCODE -ne 0) { throw 'MariaDB dump copy failed.' }
} finally {
    & docker compose --env-file $envFile exec -T db rm -f "/tmp/$dumpName" *> $null
}

$containerId = (& docker compose --env-file $envFile ps -q app).Trim()
if ([string]::IsNullOrWhiteSpace($containerId)) { throw 'The app container is not running.' }
& docker cp "${containerId}:/var/www/html/storage/app/private/." $privateRoot
if ($LASTEXITCODE -ne 0) { throw 'Private storage backup failed.' }

Copy-Item $envFile (Join-Path $configRoot '.env.production')
Copy-Item (Join-Path $projectRoot 'docker-compose.yml') $configRoot
Copy-Item (Join-Path $projectRoot 'Dockerfile') $configRoot
Copy-Item (Join-Path $PSScriptRoot 'README.md') $configRoot
Write-Host ''
Write-Host '============================================================'
Write-Host 'BACKUP COMPLETE' -ForegroundColor Green
Write-Host '============================================================'
Write-Host ''
Write-Host 'Backup created successfully.'
Write-Host ''
Write-Host 'Backup location:'
Write-Host $backupRoot
Write-Host ''
Write-Host 'Included:'
Write-Host '- MariaDB database dump'
Write-Host '- private application files'
Write-Host '- deployment configuration'
Write-Host ''
Write-Host 'The backup contains secrets in the copied production env; protect the backup directory.'
