@echo off
rem Stops the local site. The data is kept; start-local.bat brings it back.
setlocal
cd /d "%~dp0"
docker compose -p tadawul-local -f deploy\docker-compose.yml --env-file deploy\.env.local down
pause
