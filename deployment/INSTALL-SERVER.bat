@echo off
setlocal EnableExtensions EnableDelayedExpansion
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0INSTALL-SERVER.ps1"
set "INSTALL_EXIT=%ERRORLEVEL%"
if not "%INSTALL_EXIT%"=="0" (
    echo.
    echo INSTALLATION FAILED
    echo See log: %~dp0install.log
    pause
) else (
    set "SERVER_PORT=8080"
    if exist "%~dp0..\.env.production" (
        for /f "tokens=1,* delims==" %%A in ('findstr /b "HTTP_PORT=" "%~dp0..\.env.production" 2^>nul') do set "SERVER_PORT=%%B"
    )
    echo.
    echo ============================================================
    echo [9/9] 100%% INSTALLATION COMPLETE
    echo ============================================================
    echo.
    echo Server is running.
    echo.
    echo Local URL:
    echo http://localhost:!SERVER_PORT!
    echo.
    echo Server port:
    echo !SERVER_PORT!
    echo.
    echo Login:
    echo http://localhost:!SERVER_PORT!/login
    echo.
    echo Next actions:
    echo.
    echo - START-SERVER.bat - start server
    echo - STOP-SERVER.bat - stop server
    echo - STATUS-SERVER.bat - server status
    echo - BACKUP-SERVER.bat - backup
    echo - CONFIGURE-PUBLIC-SERVER.bat - configure public HTTPS access
    echo.
    echo Docker Desktop must be running.
    echo.
    echo Window will close automatically after 60 seconds.
    echo Press any key to close now.
    echo ============================================================
    timeout /t 60 >nul
)
exit /b %INSTALL_EXIT%
