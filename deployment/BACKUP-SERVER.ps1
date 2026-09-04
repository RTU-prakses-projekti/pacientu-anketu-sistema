$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $projectRoot
$envFile = Join-Path $projectRoot '.env.production'
if (-not (Test-Path $envFile)) { throw 'Missing .env.production. Run INSTALL-SERVER.bat first.' }

function Get-EnvValue([string] $key) {
    $line = Get-Content $envFile | Where-Object { $_ -match "^$([regex]::Escape($key))=" } | Select-Object -First 1
    if ($null -eq $line) { return '' }
    return ($line -replace "^$([regex]::Escape($key))=", '').Trim().Trim('"')
}

$env:DEPLOY_ENV_FILE = '.env.production'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $PSScriptRoot (Join-Path 'backups' $stamp)
$privateRoot = Join-Path $backupRoot 'storage-app-private'
$configRoot = Join-Path $backupRoot 'deployment-config'
New-Item -ItemType Directory -Force -Path $privateRoot, $configRoot | Out-Null

$dbName = Get-EnvValue 'MARIADB_DATABASE'
if ([string]::IsNullOrWhiteSpace($dbName)) { $dbName = Get-EnvValue 'DB_DATABASE' }
$dbPassword = Get-EnvValue 'MARIADB_ROOT_PASSWORD'
if ([string]::IsNullOrWhiteSpace($dbPassword)) { throw 'MARIADB_ROOT_PASSWORD is missing from .env.production.' }

$dumpPath = Join-Path $backupRoot 'database.sql'
$dumpName = "backup-$stamp.sql"
$dumpCommand = 'mariadb-dump --single-transaction --routines --events --hex-blob -uroot --databases "$MYSQL_DATABASE" > /tmp/' + $dumpName
$dbContainerId = (& docker compose --env-file $envFile ps -q db).Trim()
if ([string]::IsNullOrWhiteSpace($dbContainerId)) { throw 'The database container is not running.' }
try {
    & docker compose --env-file $envFile exec -T -e "MYSQL_PWD=$dbPassword" -e "MYSQL_DATABASE=$dbName" db sh -c $dumpCommand
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
Write-Host "Backup created: $backupRoot"
Write-Host 'The backup contains secrets in the copied production env; protect the backup directory.'
