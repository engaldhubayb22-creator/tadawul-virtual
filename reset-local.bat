@echo off
rem Stops the local site and DELETES its data (accounts, trades, everything).
setlocal
cd /d "%~dp0"
echo This deletes all local data of the site.
choice /m "Continue"
if errorlevel 2 exit /b 0
docker compose -p tadawul-local -f deploy\docker-compose.yml --env-file deploy\.env.local down -v
pause
