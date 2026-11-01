@echo off
title Toffee Live TV Restream Server
cd /d "%~dp0"

echo ========================================================
echo        Starting Toffee Live TV Restream ^& Web Player
echo ========================================================
echo.

where python >nul 2>nul
if %errorlevel% neq 0 (
    echo [ERROR] Python was not found in PATH!
    pause
    exit /b
)

start "" cmd /c "timeout /t 2 /nobreak >nul & start http://localhost:8081"
python run_server.py --port 8081

pause
