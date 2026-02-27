Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'ops/photobox_ops.ps1')

$repoRoot = Get-RepoRoot
$config = Get-PhotoboxConfig -RepoRoot $repoRoot
Ensure-PhotoboxDirectory -Path $config.data_path
Ensure-PhotoboxDirectory -Path $config.logs_path

$supervisorLog = Join-Path $config.logs_path 'supervisor.log'
$watcherLog = Join-Path $config.logs_path 'watcher.log'
$phpLog = Join-Path $config.logs_path 'php.log'
$lastImageMarker = Join-Path $config.logs_path 'last_image_unix.txt'

Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message 'Start von start.ps1 initiiert.'

$state = @{
    supervisor_pid = $PID
    php_pid = 0
    started_at = (Get-Date).ToString('s')
    watcher_active = $false
    last_heartbeat = (Get-Date).ToString('s')
    port = [int]$config.port
    status = 'BOOTING'
    crash_count = 0
    php_backoff_seconds = 0
}
Save-PhotoboxState -Config $config -State $state

$phpExe = Get-PhpExecutable -RepoRoot $repoRoot -SupervisorLog $supervisorLog
Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "PHP: $phpExe"

$phpConfigResult = Test-PhpConfig -PhpExe $phpExe -PhpLog $phpLog -SupervisorLog $supervisorLog
if (-not $phpConfigResult.Ok) {
    $state.status = 'ERROR'
    $state.last_heartbeat = (Get-Date).ToString('s')
    Save-PhotoboxState -Config $config -State $state

    $msg = 'PHP-Konfiguration fehlerhaft (siehe php.log: php --ini / php -m / Parse-Error). Start abgebrochen.'
    Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message $msg
    Write-Error $msg
    exit 2
}

$sqliteResult = Test-SqliteSupport -PhpConfigResult $phpConfigResult -SupervisorLog $supervisorLog -PhpLog $phpLog
if (-not $sqliteResult.Ok) {
    $state.status = 'ERROR'
    $state.last_heartbeat = (Get-Date).ToString('s')
    Save-PhotoboxState -Config $config -State $state

    $msg = 'SQLite-Treiber fehlt (pdo_sqlite/sqlite3). Start abgebrochen, kein Restart-Loop.'
    Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message $msg
    Write-Error $msg
    exit 2
}

if (-not (Test-PortAvailable -Port ([int]$config.port))) {
    $msg = "Port belegt: $($config.port)"
    Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message $msg
    throw $msg
}

Ensure-FirewallRule -Port ([int]$config.port) -SupervisorLog $supervisorLog
Test-WatchPathWritable -WatchPath $config.watch_path -SupervisorLog $supervisorLog
Test-PrinterStatus -Config $config -SupervisorLog $supervisorLog
Test-CameraStatus -SupervisorLog $supervisorLog

$phpRuntime = Start-PhotoboxPhpServer -PhpExe $phpExe -Config $config -PhpLog $phpLog
$phpProcess = $phpRuntime.Process
Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "PHP Webserver gestartet (PID $($phpProcess.Id)) auf Port $($config.port)."

$watcherBundle = Start-PhotoboxWatcher -PhpExe $phpExe -Config $config -WatcherLog $watcherLog -LastImageMarker $lastImageMarker
Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message 'Watcher gestartet (Created/Renamed auf JPG/JPEG).'

$state.php_pid = $phpProcess.Id
$state.watcher_active = $true
$state.last_heartbeat = (Get-Date).ToString('s')
$state.status = 'RUNNING'
Save-PhotoboxState -Config $config -State $state

$crashCount = 0
$backoffSeconds = 5
$maxCrashes = 5
$maxBackoff = 60

try {
    while ($true) {
        Start-Sleep -Seconds 5
        Sync-PhpProcessLogs -PhpRuntime $phpRuntime -PhpLog $phpLog

        if ($phpProcess.HasExited) {
            $crashCount++
            $state.crash_count = $crashCount
            $state.status = 'ERROR'
            Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message "PHP Prozess beendet (ExitCode $($phpProcess.ExitCode)). Crash $crashCount von $maxCrashes."
            foreach ($line in (Get-PhpLogTail -PhpLog $phpLog -Tail 30)) {
                Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message ("php.log tail: {0}" -f $line)
            }

            if ($crashCount -ge $maxCrashes) {
                $state.status = 'HALT'
                $state.last_heartbeat = (Get-Date).ToString('s')
                Save-PhotoboxState -Config $config -State $state
                Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message 'HALT: PHP ist 5x abgestürzt. Supervisor stoppt, um Endlos-Restart zu verhindern.'
                break
            }

            Write-PhotoboxLog -Path $supervisorLog -Level 'WARN' -Message "Backoff vor Neustart: $backoffSeconds Sekunden."
            Start-Sleep -Seconds $backoffSeconds
            $state.php_backoff_seconds = $backoffSeconds
            $backoffSeconds = [Math]::Min(($backoffSeconds * 2), $maxBackoff)

            $phpRuntime = Start-PhotoboxPhpServer -PhpExe $phpExe -Config $config -PhpLog $phpLog
            $phpProcess = $phpRuntime.Process
            Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "PHP Prozess neu gestartet (PID $($phpProcess.Id))."
            $state.php_pid = $phpProcess.Id
            $state.status = 'RUNNING'
        }

        $watcherOk = $true
        if ($null -eq $watcherBundle.Created -or $watcherBundle.Created.State -ne 'Running') { $watcherOk = $false }
        if ($null -eq $watcherBundle.Renamed -or $watcherBundle.Renamed.State -ne 'Running') { $watcherOk = $false }

        if (-not $watcherOk) {
            Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message 'Watcher Subscription inaktiv. Watcher wird neu gestartet.'
            Stop-PhotoboxWatcher -Bundle $watcherBundle
            $watcherBundle = Start-PhotoboxWatcher -PhpExe $phpExe -Config $config -WatcherLog $watcherLog -LastImageMarker $lastImageMarker
            Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message 'Watcher erfolgreich neu gestartet.'
        }

        $pending = Get-PendingPrintJobsCount -PhpExe $phpExe -Config $config
        Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "Print Queue Pending: $pending"

        $idleMinutes = [int]$config.camera_idle_minutes
        if ($idleMinutes -lt 1) { $idleMinutes = 30 }
        $nowUnix = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
        if (Test-Path -LiteralPath $lastImageMarker) {
            $lastUnix = [int64](Get-Content -LiteralPath $lastImageMarker -ErrorAction SilentlyContinue)
            if ($lastUnix -gt 0) {
                $diffMin = [math]::Floor(($nowUnix - $lastUnix) / 60)
                if ($diffMin -ge $idleMinutes) {
                    Write-PhotoboxLog -Path $supervisorLog -Level 'WARN' -Message "Seit $diffMin Minuten kein neues Bild im Watch-Ordner erkannt."
                }
            }
        }

        $state.php_pid = if ($null -ne $phpProcess) { $phpProcess.Id } else { 0 }
        $state.watcher_active = $watcherOk
        $state.last_heartbeat = (Get-Date).ToString('s')
        Save-PhotoboxState -Config $config -State $state
    }
} finally {
    Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message 'Supervisor wird beendet.'
    Stop-PhotoboxWatcher -Bundle $watcherBundle

    if ($null -ne $phpRuntime) {
        Sync-PhpProcessLogs -PhpRuntime $phpRuntime -PhpLog $phpLog
    }

    if ($null -ne $phpProcess -and -not $phpProcess.HasExited) {
        Stop-Process -Id $phpProcess.Id -Force -ErrorAction SilentlyContinue
        Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "PHP Prozess gestoppt (PID $($phpProcess.Id))."
    }

    $state.watcher_active = $false
    $state.last_heartbeat = (Get-Date).ToString('s')
    if ($state.status -eq 'RUNNING') {
        $state.status = 'STOPPED'
    }
    Save-PhotoboxState -Config $config -State $state
}
