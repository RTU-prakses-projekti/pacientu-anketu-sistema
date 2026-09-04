@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0UNINSTALL-SERVER.ps1"
set "UNINSTALL_EXIT=%ERRORLEVEL%"
echo.
if not "%UNINSTALL_EXIT%"=="0" (
    echo UNINSTALL FAILED
) else (
    echo Uninstall process finished. See the message above for the result.
)
pause
exit /b %UNINSTALL_EXIT%
