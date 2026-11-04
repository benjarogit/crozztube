[CmdletBinding()]
param(
    [string] $PhpBinary = '',
    [switch] $Foreground,
    [switch] $StopWhenEmpty
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$artisan = Join-Path $projectRoot 'artisan'
$pidFile = Join-Path $projectRoot 'storage\app\queue-worker.pid'
$stdoutLog = Join-Path $projectRoot 'storage\logs\queue-worker.log'
$stderrLog = Join-Path $projectRoot 'storage\logs\queue-worker-error.log'

function Find-PhpBinary {
    param([string] $Configured)

    $candidates = [System.Collections.Generic.List[string]]::new()
    if ($Configured.Trim() -ne '') {
        $candidates.Add($Configured.Trim().Trim('"').Trim("'"))
    }

    $envFile = Join-Path $projectRoot '.env'
    if (Test-Path -LiteralPath $envFile) {
        $line = Get-Content -LiteralPath $envFile | Where-Object { $_ -match '^PLAYHUB_PHP_BINARY=' } | Select-Object -First 1
        if ($line) {
            $value = ($line -replace '^PLAYHUB_PHP_BINARY=', '').Trim().Trim('"').Trim("'")
            if ($value -ne '') {
                $candidates.Add($value)
            }
        }
    }

    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) {
        $candidates.Add($phpCommand.Source)
    }

    $wwwRoot = Split-Path -Parent $projectRoot
    $laragonRoot = Split-Path -Parent $wwwRoot
    Get-ChildItem -Path (Join-Path $laragonRoot 'bin\php\*\php.exe') -File -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending |
        ForEach-Object { $candidates.Add($_.FullName) }

    foreach ($candidate in $candidates) {
        $expanded = [Environment]::ExpandEnvironmentVariables($candidate)
        if (Test-Path -LiteralPath $expanded -PathType Leaf) {
            return (Resolve-Path -LiteralPath $expanded).Path
        }
    }

    throw 'PHP CLI was not found. Pass -PhpBinary with the full path to php.exe.'
}

if (-not (Test-Path -LiteralPath $artisan -PathType Leaf)) {
    throw "Laravel artisan was not found at $artisan"
}

$php = Find-PhpBinary -Configured $PhpBinary
$arguments = @(
    $artisan,
    'queue:work',
    '--queue=videos,default',
    '--sleep=3',
    '--tries=2',
    '--timeout=21600'
)
if ($StopWhenEmpty) {
    $arguments += '--stop-when-empty'
}

if ($Foreground) {
    Push-Location $projectRoot
    try {
        & $php @arguments
        exit $LASTEXITCODE
    } finally {
        Pop-Location
    }
}

if (Test-Path -LiteralPath $pidFile) {
    $existingPid = Get-Content -LiteralPath $pidFile -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($existingPid -match '^\d+$') {
        $candidate = Get-CimInstance Win32_Process -Filter ('ProcessId=' + [int] $existingPid) -ErrorAction Stop
        if ($candidate -and $candidate.ExecutablePath -ieq $php -and
            $candidate.CommandLine -match [regex]::Escape($artisan) -and
            $candidate.CommandLine -match '(?:^|\s)queue:work(?:\s|$)' -and
            $candidate.CommandLine -match '--queue=videos,default(?:\s|$)') {
            Write-Output "PlayHub queue worker is already running with PID $existingPid."
            exit 0
        }
        if ($candidate -and $candidate.Name -ieq 'php.exe' -and
            ([string]::IsNullOrWhiteSpace($candidate.CommandLine) -or
                $candidate.CommandLine -match '(?:^|\s)queue:work(?:\s|$)')) {
            throw 'The stored PID belongs to a live PHP worker without verified absolute project identity. Verify the legacy worker and arrange a controlled restart after its current job finishes; no duplicate worker was started.'
        }
    }
}

# Start-Process joins arguments into a command line; quote the absolute path only
# here. Foreground invocation above passes the unquoted path as one native argument.
$arguments[0] = '"' + $artisan + '"'
$process = Start-Process `
    -FilePath $php `
    -ArgumentList $arguments `
    -WorkingDirectory $projectRoot `
    -WindowStyle Hidden `
    -RedirectStandardOutput $stdoutLog `
    -RedirectStandardError $stderrLog `
    -PassThru

Set-Content -LiteralPath $pidFile -Value $process.Id -Encoding ascii
Write-Output "PlayHub queue worker started with PID $($process.Id)."
Write-Output "Logs: $stdoutLog and $stderrLog"
