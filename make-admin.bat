@echo off
rem Gives a registered user the admin role:  make-admin.bat 05xxxxxxxx
setlocal
cd /d "%~dp0"
if "%~1"=="" (
  echo Usage: make-admin.bat 05xxxxxxxx
  pause
  exit /b 1
)
docker compose -p tadawul-local -f deploy\docker-compose.yml --env-file deploy\.env.local exec -T api php artisan app:make-admin %1
echo Sign out and sign in again on the site to see the admin panel button.
pause
