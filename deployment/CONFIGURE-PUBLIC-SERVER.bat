@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0CONFIGURE-PUBLIC-SERVER.ps1"
exit /b %ERRORLEVEL%
