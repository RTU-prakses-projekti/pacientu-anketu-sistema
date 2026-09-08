@echo off
setlocal EnableExtensions
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0RESTORE-SERVER.ps1"
set "RESTORE_EXIT=%ERRORLEVEL%"
if not "%RESTORE_EXIT%"=="0" (
    echo.
    echo RESTORE FAILED
    pause
) else (
    echo.
    echo Press any key to close.
    echo Window will close automatically after 60 seconds.
    timeout /t 60 >nul
)
exit /b %RESTORE_EXIT%
