@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0STATUS-SERVER.ps1"
exit /b %ERRORLEVEL%
