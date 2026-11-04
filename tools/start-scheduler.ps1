[CmdletBinding()]
param(
    [string] $PhpBinary = '',
    [switch] $Foreground
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$artisan = Join-Path $projectRoot 'artisan'
$pidFile = Join-Path $projectRoot 'storage\app\scheduler.pid'
$stdoutLog = Join-Path $projectRoot 'storage\logs\scheduler.log'
$stderrLog = Join-Path $projectRoot 'storage\logs\scheduler-error.log'

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
            if ($value -ne '') { $candidates.Add($value) }
        }
    }
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) { $candidates.Add($phpCommand.Source) }
    $laragonRoot = Split-Path -Parent (Split-Path -Parent $projectRoot)
    Get-ChildItem -Path (Join-Path $laragonRoot 'bin\php\*\php.exe') -File -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending |
        ForEach-Object { $candidates.Add($_.FullName) }
    foreach ($candidate in $candidates) {
        $expanded = [Environment]::ExpandEnvironmentVariables($candidate)
        if (Test-Path -LiteralPath $expanded -PathType Leaf) { return (Resolve-Path -LiteralPath $expanded).Path }
    }
    throw 'PHP CLI was not found. Pass -PhpBinary with the full path to php.exe.'
}

if (-not (Test-Path -LiteralPath $artisan -PathType Leaf)) { throw 'Laravel artisan was not found in this project.' }
$php = Find-PhpBinary -Configured $PhpBinary
$arguments = @(('"' + $artisan + '"'), 'schedule:work')
if ($Foreground) {
    Push-Location $projectRoot
    try {
        & $php $artisan 'schedule:work'
        exit $LASTEXITCODE
    } finally { Pop-Location }
}

# Serialize starts and verify the process command, not merely a reused numeric PID.
$startLock = [System.IO.File]::Open(($pidFile + '.lock'), [System.IO.FileMode]::OpenOrCreate, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
try {
    if (Test-Path -LiteralPath $pidFile) {
        $existingPid = (Get-Content -LiteralPath $pidFile -ErrorAction SilentlyContinue | Select-Object -First 1)
        if ($existingPid -match '^\d+$') {
            $candidate = Get-CimInstance Win32_Process -Filter ('ProcessId=' + [int]$existingPid) -ErrorAction Stop
            if ($candidate -and $candidate.ExecutablePath -ieq $php -and
                $candidate.CommandLine -match [regex]::Escape($artisan) -and
                $candidate.CommandLine -match '(?:^|\s)schedule:work(?:\s|$)') {
                Write-Output "PlayHub scheduler is already running with PID $existingPid."
                exit 0
            }
        }
    }
    $process = Start-Process -FilePath $php -ArgumentList $arguments -WorkingDirectory $projectRoot `
        -WindowStyle Hidden -RedirectStandardOutput $stdoutLog -RedirectStandardError $stderrLog -PassThru
    Set-Content -LiteralPath $pidFile -Value $process.Id -Encoding ascii
    Write-Output "PlayHub scheduler started with PID $($process.Id)."
    Write-Output "Logs: $stdoutLog and $stderrLog"
} finally { $startLock.Dispose() }
