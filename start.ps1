<#
.SYNOPSIS
    Starts every process the Document Compliance Checker needs.

.DESCRIPTION
    The application is three cooperating processes, not one:

      web      - serves the interface
      queue    - runs scans and analyses; WITHOUT IT NOTHING IS EVER ANALYSED
      analyzer - the Python service that reads documents

    The queue worker is the one people forget. The web interface works fine
    without it, documents just sit at Pending forever with no error anywhere -
    which looks like a broken analyzer rather than a missing worker.

.PARAMETER NoAnalyzer
    Skip the Python service. Documents are still discovered and queued; they
    stay Pending until an analyzer is reachable.

.PARAMETER Scheduler
    Also run the scheduler, which triggers scans on each source's interval.
    Not needed if you only ever press "Scan now".

.EXAMPLE
    .\start.ps1
#>

[CmdletBinding()]
param(
    [switch] $NoAnalyzer,
    [switch] $Scheduler
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

# The toolchain lives under the user profile rather than being installed
# machine-wide, so PATH is set here instead of relying on the shell's.
$php = 'C:\Users\itsupport\Tools\php84'
$pgsql = 'C:\Program Files\PostgreSQL\18\bin'
$env:Path = "$php;C:\Users\itsupport\Tools\composer;$pgsql;$env:Path"

$pythonExe = Join-Path $root 'analyzer\.venv\Scripts\python.exe'

Write-Host ''
Write-Host '  Document Compliance Checker' -ForegroundColor Cyan
Write-Host '  ---------------------------' -ForegroundColor Cyan

# --- Preflight ------------------------------------------------------------
# Checked up front because each of these fails much less clearly later: a
# missing queue worker looks like a broken analyzer, and an unreachable
# database looks like a broken application.

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw "PHP was not found. Expected it at $php"
}

if (-not (Test-Path (Join-Path $root 'vendor\autoload.php'))) {
    throw 'Dependencies are missing. Run: composer install'
}

if (-not (Test-Path (Join-Path $root 'public\build\manifest.json'))) {
    throw 'Frontend assets are not built. Run: npm install; npm run build'
}

# A port already in use kills the whole run, and the message it produces -
# "[Errno 10048] only one usage of each socket address" buried in uvicorn's
# output - reads like a bug rather than "something else is already running".
# Usually it is a previous start.ps1 that was closed without Ctrl+C.
function Test-PortFree {
    param([int] $Port, [string] $Label)

    $listener = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
        Select-Object -First 1

    if (-not $listener) {
        return
    }

    $owner = Get-Process -Id $listener.OwningProcess -ErrorAction SilentlyContinue

    Write-Host ''
    Write-Host "  Port $Port is already in use by $($owner.ProcessName) (PID $($listener.OwningProcess))." -ForegroundColor Red
    Write-Host "  That is where $Label wants to listen." -ForegroundColor Red
    Write-Host ''
    Write-Host "  Stop it with:  Stop-Process -Id $($listener.OwningProcess) -Force"
    Write-Host ''

    throw "Port $Port is not free."
}

Test-PortFree -Port 8000 -Label 'the web server'

if (-not $NoAnalyzer) {
    Test-PortFree -Port 8001 -Label 'the analyzer'
}

Write-Host '  checking database...' -NoNewline
try {
    php artisan db:show --json 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { throw }
    Write-Host ' ok' -ForegroundColor Green
} catch {
    Write-Host ' FAILED' -ForegroundColor Red
    throw 'Could not reach PostgreSQL. Check the DB_* values in .env.'
}

$runAnalyzer = -not $NoAnalyzer

if ($runAnalyzer -and -not (Test-Path $pythonExe)) {
    Write-Host '  analyzer virtualenv not found - starting without it.' -ForegroundColor Yellow
    Write-Host '  (cd analyzer; python -m venv .venv; .venv\Scripts\pip install -r requirements.txt)'
    $runAnalyzer = $false
}

# --- Build the process list ----------------------------------------------
$commands = @(
    'php artisan serve'
    'php artisan queue:work --tries=3'
)
$names = @('web', 'queue')
$colors = @('#93c5fd', '#c4b5fd')

if ($runAnalyzer) {
    # cd first so the analyzer picks up its own .env.
    $commands += "cd analyzer && `"$pythonExe`" -m uvicorn app.main:app --port 8001"
    $names += 'analyzer'
    $colors += '#fb7185'
}

if ($Scheduler) {
    $commands += 'php artisan schedule:work'
    $names += 'scheduler'
    $colors += '#fdba74'
}

Write-Host ''
Write-Host "  web       http://127.0.0.1:8000"
if ($runAnalyzer) { Write-Host "  analyzer  http://127.0.0.1:8001/docs" }
Write-Host "  processes $($names -join ', ')"
Write-Host ''
Write-Host '  Ctrl+C stops everything.' -ForegroundColor DarkGray
Write-Host ''

# concurrently ships with the frontend dev dependencies and gives one
# combined, colour-coded log plus a single Ctrl+C that stops all of them.
$quoted = $commands | ForEach-Object { '"' + $_ + '"' }

& npx concurrently -c ($colors -join ',') --names ($names -join ',') --kill-others @quoted
