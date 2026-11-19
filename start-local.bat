@echo off
rem Runs the whole site on this computer with Docker Desktop and opens it
rem in the browser. Safe to run again: it rebuilds what changed and keeps the data.
setlocal
cd /d "%~dp0"

where docker >nul 2>nul
if errorlevel 1 goto nodocker
docker info >nul 2>nul
if errorlevel 1 goto notrunning

if exist deploy\.env.local goto haveenv
echo Creating local settings (deploy\.env.local)...
powershell -NoProfile -Command "$k=[Convert]::ToBase64String([byte[]](1..32 | ForEach-Object { Get-Random -Maximum 256 })); $p=-join((48..57)+(97..122) | Get-Random -Count 24 | ForEach-Object { [char]$_ }); Set-Content -Encoding ascii -Path 'deploy\.env.local' -Value @('POSTGRES_PASSWORD='+$p, 'APP_KEY=base64:'+$k, 'APP_URL=http://localhost:8080', 'HTTP_PORT=8080', 'BOT_INTERVAL_MS=1000')"
if errorlevel 1 goto failed
:haveenv

echo.
echo Building and starting. The first run downloads images and can take 5 to 15 minutes...
echo.
docker compose -p tadawul-local -f deploy\docker-compose.yml --env-file deploy\.env.local up -d --build
if errorlevel 1 goto failed

rem Local trial: let the market trade at any hour. Change it back in the admin panel.
docker compose -p tadawul-local -f deploy\docker-compose.yml --env-file deploy\.env.local exec -T postgres psql -U tadawul -d tadawul -q -c "UPDATE settings SET value='false' WHERE key='market_hours_enforced'"

echo.
echo The site is running at http://localhost:8080
echo To make yourself an admin: register on the site, then run  make-admin.bat 05xxxxxxxx
echo To stop it: run stop-local.bat
start "" http://localhost:8080
pause
exit /b 0

:nodocker
echo Docker Desktop is not installed.
echo Install it from https://www.docker.com/products/docker-desktop/ then run this file again.
pause
exit /b 1

:notrunning
echo Docker Desktop is installed but not running. Start it, wait until it says it is running, then run this file again.
pause
exit /b 1

:failed
echo.
echo Something failed. Copy the messages above and send them to get it fixed.
pause
exit /b 1
