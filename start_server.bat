@echo off
title Toffee Live TV PHP Server
cd /d "%~dp0"

echo ========================================================
echo        Starting Toffee Live TV PHP Server
echo ========================================================
echo.

where php >nul 2>nul
if %errorlevel% neq 0 (
    echo [ERROR] PHP was not found in PATH!
    pause
    exit /b
)

start "" cmd /c "timeout /t 2 /nobreak >nul & start http://localhost:8081"
php -S 0.0.0.0:8081 index.php

pause
