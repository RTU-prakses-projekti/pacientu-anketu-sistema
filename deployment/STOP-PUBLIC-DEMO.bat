@echo off
setlocal EnableExtensions
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0STOP-PUBLIC-DEMO.ps1"
set "DEMO_EXIT=%ERRORLEVEL%"
echo.
echo Press any key to close.
echo Window will close automatically after 60 seconds.
timeout /t 60 >nul
exit /b %DEMO_EXIT%
