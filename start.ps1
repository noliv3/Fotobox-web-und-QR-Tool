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

$phpExe = Get-PhpExecutable -RepoRoot $repoRoot -SupervisorLog $supervisorLog
Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "PHP: $phpExe"

if (-not (Test-PortAvailable -Port ([int]$config.port))) {
    $msg = "Port belegt: $($config.port)"
    Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message $msg
    throw $msg
}

Ensure-FirewallRule -Port ([int]$config.port) -SupervisorLog $supervisorLog
Test-WatchPathWritable -WatchPath $config.watch_path -SupervisorLog $supervisorLog
Test-PrinterStatus -Config $config -SupervisorLog $supervisorLog
Test-CameraStatus -SupervisorLog $supervisorLog

$phpProcess = Start-PhotoboxPhpServer -PhpExe $phpExe -Config $config -PhpLog $phpLog
Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "PHP Webserver gestartet (PID $($phpProcess.Id)) auf Port $($config.port)."

$watcherBundle = Start-PhotoboxWatcher -PhpExe $phpExe -Config $config -WatcherLog $watcherLog -LastImageMarker $lastImageMarker
Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message 'Watcher gestartet (Created/Renamed auf JPG/JPEG).'

$state = @{
    supervisor_pid = $PID
    php_pid = $phpProcess.Id
    started_at = (Get-Date).ToString('s')
    watcher_active = $true
    last_heartbeat = (Get-Date).ToString('s')
    port = [int]$config.port
}
Save-PhotoboxState -Config $config -State $state

try {
    while ($true) {
        Start-Sleep -Seconds 5

        if ($phpProcess.HasExited) {
            Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message "PHP Prozess beendet (ExitCode $($phpProcess.ExitCode)). Neustart."
            $phpProcess = Start-PhotoboxPhpServer -PhpExe $phpExe -Config $config -PhpLog $phpLog
            Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "PHP Prozess neu gestartet (PID $($phpProcess.Id))."
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

        $state.php_pid = $phpProcess.Id
        $state.watcher_active = $watcherOk
        $state.last_heartbeat = (Get-Date).ToString('s')
        Save-PhotoboxState -Config $config -State $state
    }
} finally {
    Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message 'Supervisor wird beendet.'
    Stop-PhotoboxWatcher -Bundle $watcherBundle

    if ($null -ne $phpProcess -and -not $phpProcess.HasExited) {
        Stop-Process -Id $phpProcess.Id -Force -ErrorAction SilentlyContinue
        Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "PHP Prozess gestoppt (PID $($phpProcess.Id))."
    }

    $state.watcher_active = $false
    $state.last_heartbeat = (Get-Date).ToString('s')
    Save-PhotoboxState -Config $config -State $state
}
