<#
.SYNOPSIS
    Start, stop or check the local SUN-ATES development stack on Windows.

.DESCRIPTION
    start   Launches the three things development needs, skipping any that are already up:
              1. MySQL 8.4 on 127.0.0.1:3307   (portable install, see README "Local setup")
              2. Laravel API + staff console   http://127.0.0.1:8000
              3. Vite dev server for the PWA   http://127.0.0.1:5173  (proxies /api to :8000)
    stop    Stops whatever this script started, and shuts MySQL down cleanly.
    status  Shows which ports are listening.

.PARAMETER Tools
    Folder holding the portable php\ and mysql-8.4.11-winx64\ installs. Defaults to %USERPROFILE%\tools.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\dev.ps1 start
#>
param(
    [ValidateSet('start', 'stop', 'status')][string]$Action = 'start',
    [string]$Tools = (Join-Path $env:USERPROFILE 'tools')
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = Join-Path $Tools 'php\php.exe'
$mysqlBin = Join-Path $Tools 'mysql-8.4.11-winx64\bin'
$mysqlIni = Join-Path $Tools 'mysql-8.4.ini'
$pidFile = Join-Path $PSScriptRoot '.dev-pids.json'

$ports = [ordered]@{ 'MySQL' = 3307; 'Laravel API + console' = 8000; 'PWA (Vite)' = 5173 }

function Test-Port([int]$Port) {
    [bool](Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)
}

function Wait-Port([int]$Port, [int]$Seconds = 30) {
    for ($i = 0; $i -lt $Seconds * 2; $i++) {
        if (Test-Port $Port) { return $true }
        Start-Sleep -Milliseconds 500
    }
    return $false
}

$logDir = Join-Path $env:TEMP 'sunates-dev'
New-Item -ItemType Directory -Force $logDir | Out-Null

# Services run detached, but with their output sent to log files: Vite exits if it has no stdio at all,
# and the logs are the first place to look when something will not start.
function Start-Service([string]$Name, [string]$File, [string[]]$Arguments, [string]$WorkDir) {
    # Windows PowerShell 5.1 does not quote array elements, and this project's path contains spaces.
    $quoted = $Arguments | ForEach-Object { if ($_ -match '\s') { '"' + $_ + '"' } else { $_ } }
    Start-Process -FilePath $File -ArgumentList $quoted -WorkingDirectory $WorkDir -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $logDir "$Name.out.log") -RedirectStandardError (Join-Path $logDir "$Name.err.log")
}

function Read-Pids { if (Test-Path $pidFile) { Get-Content $pidFile -Raw | ConvertFrom-Json } else { [pscustomobject]@{} } }

function Save-Pid([string]$Name, [int]$ProcessId) {
    $pids = @{}
    (Read-Pids).PSObject.Properties | ForEach-Object { $pids[$_.Name] = $_.Value }
    $pids[$Name] = $ProcessId
    $pids | ConvertTo-Json | Set-Content $pidFile -Encoding ascii
}

function Show-Status {
    foreach ($name in $ports.Keys) {
        '{0,-24} port {1,-5} {2}' -f $name, $ports[$name], $(if (Test-Port $ports[$name]) { 'UP' } else { 'down' })
    }
}

switch ($Action) {
    'status' { Show-Status }

    'start' {
        foreach ($required in $php, "$mysqlBin\mysqld.exe") {
            if (-not (Test-Path $required)) { throw "Missing $required. Install PHP/MySQL as described in README.md, or pass -Tools <folder>." }
        }

        if (-not (Test-Port 3307)) {
            Write-Host 'Starting MySQL on 3307...'
            $p = Start-Service 'mysql' "$mysqlBin\mysqld.exe" @("--defaults-file=$mysqlIni", '--console') $Tools
            Save-Pid 'mysql' $p.Id
            if (-not (Wait-Port 3307)) { throw 'MySQL did not start; see mysql-data\mysqld.err in your tools folder.' }
        }

        if (-not (Test-Port 8000)) {
            Write-Host 'Starting Laravel on 8000...'
            $env:PHP_CLI_SERVER_WORKERS = '4'
            $p = Start-Service 'laravel' $php @('artisan', 'serve', '--host=127.0.0.1', '--port=8000') (Join-Path $root 'backend')
            Save-Pid 'laravel' $p.Id
            if (-not (Wait-Port 8000)) { throw "Laravel did not start; see $logDir\laravel.err.log" }
        }

        if (-not (Test-Port 5173)) {
            Write-Host 'Starting Vite on 5173...'
            $vite = Join-Path $root 'pwa\node_modules\vite\bin\vite.js'
            if (-not (Test-Path $vite)) { throw 'Run "npm install" in pwa\ first.' }
            $p = Start-Service 'vite' 'node.exe' @($vite, '--host', '127.0.0.1', '--port', '5173', '--strictPort') (Join-Path $root 'pwa')
            Save-Pid 'vite' $p.Id
            if (-not (Wait-Port 5173)) { throw "Vite did not start; see $logDir\vite.err.log" }
        }

        Show-Status
        Write-Host "`nStaff console: http://127.0.0.1:8000/admin    Alumni PWA: http://127.0.0.1:5173"
    }

    'stop' {
        $pids = Read-Pids
        foreach ($name in 'vite', 'laravel') {   # MySQL is stopped below by asking it to shut down cleanly
            $id = $pids.$name
            if ($id) { & taskkill.exe /PID $id /T /F 2>&1 | Out-Null }   # /T also ends artisan's worker children
        }

        if (Test-Port 3307) {
            & "$mysqlBin\mysqladmin.exe" -h 127.0.0.1 -P 3307 -u root shutdown 2>&1 | Out-Null
        }

        if (Test-Path $pidFile) { Remove-Item -LiteralPath $pidFile -Force }
        Start-Sleep -Seconds 2
        Show-Status
    }
}
