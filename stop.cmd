@echo off
rem SUN Alumni: double-click to stop what start.cmd started.
setlocal
cd /d "%~dp0"
if exist "%USERPROFILE%\tools\php\php.exe" (
  powershell -NoProfile -ExecutionPolicy Bypass -File "scripts\dev.ps1" stop
) else (
  echo Close the three "SUN Alumni" windows (server, queue, scheduler) to stop.
)
pause
endlocal
