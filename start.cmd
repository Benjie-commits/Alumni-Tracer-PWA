@echo off
rem SUN Alumni: double-click to start everything and open the app. Stop it with stop.cmd.
setlocal
cd /d "%~dp0"
title SUN Alumni launcher

set "TOOLS=%USERPROFILE%\tools"

rem The alumni app is compiled into the Laravel server, so build it once (needs Node).
if not exist "backend\public\app\index.html" (
  echo Building the alumni app for the first time, this takes a minute...
  pushd pwa
  if not exist node_modules call npm install
  call npm run build
  popd
)

if exist "%TOOLS%\php\php.exe" (
  rem The portable setup this project was built on: PHP + MySQL (port 3307) under %USERPROFILE%\tools.
  powershell -NoProfile -ExecutionPolicy Bypass -File "scripts\dev.ps1" start
) else (
  rem Your own PHP and MySQL: MySQL must already be running and backend\.env filled in (see README).
  where php >nul 2>nul || (echo PHP was not found. See "Local setup" in README.md. & pause & exit /b 1)
  echo Starting the server, queue worker and scheduler in their own windows...
  start "SUN Alumni server" /d backend cmd /k php artisan serve --host=127.0.0.1 --port=8000
  start "SUN Alumni queue" /d backend cmd /k php artisan queue:work
  start "SUN Alumni scheduler" /d backend cmd /k php artisan schedule:work
  timeout /t 4 /nobreak >nul
)

echo.
echo   Everything is at ONE address, with ONE sign-in:  http://127.0.0.1:8000
echo   Alumni and students land in the app; staff are taken to the dashboard.
echo.
if not defined SUNATES_NO_OPEN start "" "http://127.0.0.1:8000"
endlocal
