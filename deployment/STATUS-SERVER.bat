@echo off
setlocal EnableExtensions
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0STATUS-SERVER.ps1"
set "STATUS_EXIT=%ERRORLEVEL%"
echo.
echo Press any key to close.
echo Window will close automatically after 60 seconds.
echo ============================================================
timeout /t 60 >nul
exit /b %STATUS_EXIT%
