@echo off
setlocal EnableExtensions
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0START-PUBLIC-DEMO.ps1"
set "DEMO_EXIT=%ERRORLEVEL%"
if not "%DEMO_EXIT%"=="0" (
    echo.
    echo PUBLIC DEMO FAILED
    pause
) else (
    echo.
    echo Press any key to close this window.
    echo Window will close automatically after 60 seconds.
    timeout /t 60 >nul
)
exit /b %DEMO_EXIT%
