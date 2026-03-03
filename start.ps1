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
$watcherErrorMarker = Join-Path $config.logs_path 'watcher_error_unix.txt'

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

$phpLaunchPlan = Get-PhpLaunchPlan -PhpExe $phpExe -PhpLog $phpLog -SupervisorLog $supervisorLog
$phpConfigResult = Test-PhpConfig -PhpExe $phpExe -PhpLog $phpLog -SupervisorLog $supervisorLog -PrefixArgs $phpLaunchPlan.DiagnosticPrefixArgs
if (-not $phpConfigResult.Ok) {
    $state.status = 'ERROR'
    $state.last_heartbeat = (Get-Date).ToString('s')
    Save-PhotoboxState -Config $config -State $state

    $msg = 'PHP-Konfiguration fehlerhaft (siehe php.log: php --ini / php -m / Parse-Error). Start abgebrochen.'
    Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message $msg
    Write-Error $msg
    exit 2
}

$sqliteResult = Test-SqliteSupport -PhpConfigResult $phpConfigResult -SupervisorLog $supervisorLog -PhpLog $phpLog -Mode $phpLaunchPlan.Mode
if (-not $sqliteResult.Ok) {
    $state.status = 'ERROR'
    $state.last_heartbeat = (Get-Date).ToString('s')
    Save-PhotoboxState -Config $config -State $state

    $msg = 'SQLite-Treiber fehlt (pdo_sqlite/sqlite3). Start abgebrochen, kein Restart-Loop.'
    Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message $msg
    Write-Error $msg
    exit 2
}

$zipResult = Test-ZipSupport -PhpExe $phpExe -SupervisorLog $supervisorLog -PhpLog $phpLog -PrefixArgs $phpLaunchPlan.DiagnosticPrefixArgs
if (-not $zipResult.Ok) {
    $state.status = 'ERROR'
    $state.last_heartbeat = (Get-Date).ToString('s')
    Save-PhotoboxState -Config $config -State $state

    $msg = 'PHP zip extension fehlt. Start abgebrochen, kein Restart-Loop.'
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
Test-ImportSourcePathAccessible -Config $config -SupervisorLog $supervisorLog
Test-PrinterStatus -Config $config -SupervisorLog $supervisorLog
Test-CameraStatus -SupervisorLog $supervisorLog

$phpRuntime = Start-PhotoboxPhpServer -PhpExe $phpExe -Config $config -PhpLog $phpLog -PhpPrefixArgs $phpLaunchPlan.PhpPrefixArgs -SupervisorLog $supervisorLog
$phpProcess = $phpRuntime.Process
Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "PHP Webserver gestartet (PID $($phpProcess.Id)) auf Port $($config.port)."

$watcherBundle = Start-PhotoboxWatcher -PhpExe $phpExe -Config $config -WatcherLog $watcherLog -LastImageMarker $lastImageMarker -WatcherErrorMarker $watcherErrorMarker
Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "Watcher gestartet (Created/Renamed auf JPG/JPEG, Quelle: $($config.import_source_path), rekursiv=$([string]($config.import_mode -eq 'sd_card')))."

$state.php_pid = $phpProcess.Id
$state.watcher_active = $true
$state.last_heartbeat = (Get-Date).ToString('s')
$state.status = 'RUNNING'
Save-PhotoboxState -Config $config -State $state

$crashCount = 0
$backoffSeconds = 5
$maxCrashes = 5
$maxBackoff = 60
$global:LastWatcherEventTs = 0
$lastCleanupRunAt = [DateTime]::UtcNow.AddMinutes(-60)

try {
    while ($true) {
        Start-Sleep -Seconds 5
        try {
            Sync-PhpProcessLogs -PhpRuntime $phpRuntime -PhpLog $phpLog
        } catch {
            Write-PhotoboxLog -Path $supervisorLog -Level 'WARN' -Message ("Log-Sync Fehler ignoriert (Supervisor läuft weiter): {0}" -f $_.Exception.Message)
        }

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

            $phpRuntime = Start-PhotoboxPhpServer -PhpExe $phpExe -Config $config -PhpLog $phpLog -PhpPrefixArgs $phpLaunchPlan.PhpPrefixArgs -SupervisorLog $supervisorLog
            $phpProcess = $phpRuntime.Process
            Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "PHP Prozess neu gestartet (PID $($phpProcess.Id))."
            $state.php_pid = $phpProcess.Id
            $state.status = 'RUNNING'
        }

        $watcherOk = $true
        if ($null -eq $watcherBundle -or $null -eq $watcherBundle.Watcher) { $watcherOk = $false }
        if ($watcherOk -and $watcherBundle.Watcher.EnableRaisingEvents -ne $true) { $watcherOk = $false }
        if ($watcherOk -and $watcherBundle.HandlerRegistered -ne $true) { $watcherOk = $false }

        $nowUnix = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
        if ($watcherOk -and (Test-Path -LiteralPath $watcherErrorMarker)) {
            $lastErrUnix = [int64](Get-Content -LiteralPath $watcherErrorMarker -ErrorAction SilentlyContinue)
            if ($lastErrUnix -gt 0 -and ($nowUnix - $lastErrUnix) -lt 60) {
                $watcherOk = $false
                Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message 'Watcher-Health Fehler: Exception in den letzten 60s erkannt.'
            }
        }

        if (-not $watcherOk) {
            Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message 'Watcher defekt erkannt. Watcher wird neu gestartet.'
            Stop-PhotoboxWatcher -Bundle $watcherBundle
            $watcherBundle = Start-PhotoboxWatcher -PhpExe $phpExe -Config $config -WatcherLog $watcherLog -LastImageMarker $lastImageMarker -WatcherErrorMarker $watcherErrorMarker
            Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message 'Watcher erfolgreich neu gestartet.'
        }

        $pending = Get-PendingPrintJobsCount -PhpExe $phpExe -Config $config
        Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "Print Queue Pending: $pending"
        if ($pending -gt 0) {
            try {
                $printResult = & $phpExe (Join-Path $repoRoot 'import/print_worker.php') 'run' 2>&1
                $printText = (($printResult | ForEach-Object { [string]$_ }) -join ' | ').Trim()
                if ($LASTEXITCODE -eq 0) {
                    if ($printText -ne '') {
                        Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message ("print_worker.php run: {0}" -f $printText)
                    } else {
                        Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message 'print_worker.php run ohne Ausgabe abgeschlossen.'
                    }
                } else {
                    Write-PhotoboxLog -Path $supervisorLog -Level 'WARN' -Message ("print_worker.php run ExitCode={0}; Ausgabe: {1}" -f $LASTEXITCODE, $printText)
                }
            } catch {
                Write-PhotoboxLog -Path $supervisorLog -Level 'WARN' -Message ("print_worker.php run Fehler: {0}" -f $_.Exception.Message)
            }
        }

        $nowUtc = [DateTime]::UtcNow
        if (($nowUtc - $lastCleanupRunAt).TotalMinutes -ge 60) {
            try {
                $cleanupResult = & $phpExe (Join-Path $repoRoot 'import/import_service.php') 'cleanup' 2>&1
                $cleanupText = (($cleanupResult | ForEach-Object { [string]$_ }) -join ' | ').Trim()
                if ($LASTEXITCODE -eq 0) {
                    if ($cleanupText -ne '') {
                        Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message ("import_service.php cleanup: {0}" -f $cleanupText)
                    } else {
                        Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message 'import_service.php cleanup ohne Ausgabe abgeschlossen.'
                    }
                } else {
                    Write-PhotoboxLog -Path $supervisorLog -Level 'WARN' -Message ("import_service.php cleanup ExitCode={0}; Ausgabe: {1}" -f $LASTEXITCODE, $cleanupText)
                }
            } catch {
                Write-PhotoboxLog -Path $supervisorLog -Level 'WARN' -Message ("import_service.php cleanup Fehler: {0}" -f $_.Exception.Message)
            }
            $lastCleanupRunAt = $nowUtc
        }

        $idleMinutes = [int]$config.camera_idle_minutes
        if ($idleMinutes -lt 1) { $idleMinutes = 30 }
        if (Test-Path -LiteralPath $lastImageMarker) {
            $lastUnix = [int64](Get-Content -LiteralPath $lastImageMarker -ErrorAction SilentlyContinue)
            $global:LastWatcherEventTs = $lastUnix
            if ($lastUnix -gt 0) {
                $diffMin = [math]::Floor(($nowUnix - $lastUnix) / 60)
                if ($diffMin -ge $idleMinutes) {
                    Write-PhotoboxLog -Path $supervisorLog -Level 'WARN' -Message "Seit $diffMin Minuten kein neues JPG/JPEG in der Import-Quelle erkannt."
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
        try {
            Sync-PhpProcessLogs -PhpRuntime $phpRuntime -PhpLog $phpLog
        } catch {
            Write-PhotoboxLog -Path $supervisorLog -Level 'WARN' -Message ("Abschluss-Log-Sync Fehler ignoriert: {0}" -f $_.Exception.Message)
        }
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
