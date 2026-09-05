@echo off
setlocal EnableExtensions
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0BACKUP-SERVER.ps1"
set "BACKUP_EXIT=%ERRORLEVEL%"
if not "%BACKUP_EXIT%"=="0" (
    echo.
    echo BACKUP FAILED
    echo See the error above and check the backup configuration.
    pause
) else (
    echo.
    echo Press any key to close.
    echo Window will close automatically after 60 seconds.
    echo ============================================================
    timeout /t 60 >nul
)
exit /b %BACKUP_EXIT%
