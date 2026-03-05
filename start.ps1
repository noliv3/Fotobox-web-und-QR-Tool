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

function Ensure-FirewallPort5513 {
    param([Parameter(Mandatory = $true)][string]$SupervisorLog)

    $ruleName = 'Photobooth digiCamControl Webserver 5513'
    $exists = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    if ($null -ne $exists) {
        Write-PhotoboxLog -Path $SupervisorLog -Level 'INFO' -Message "Firewall-Regel bereits vorhanden: $ruleName"
        return
    }

    if (-not (Test-PhotoboxAdmin)) {
        $msg = 'Firewall-Regel für digiCamControl (TCP 5513) konnte ohne Admin-Rechte nicht erstellt werden.'
        Write-PhotoboxLog -Path $SupervisorLog -Level 'ERROR' -Message $msg
        throw $msg
    }

    New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Action Allow -Protocol TCP -LocalPort 5513 | Out-Null
    Write-PhotoboxLog -Path $SupervisorLog -Level 'INFO' -Message "Firewall-Regel erstellt: $ruleName"
}

function Get-DigiCamControlExecutablePath {
    $paths = @(
        'C:\Program Files\digiCamControl\digiCamControl.exe',
        'C:\Program Files (x86)\digiCamControl\digiCamControl.exe',
        'C:\Program Files\digiCamControl\CameraControl.exe',
        'C:\Program Files (x86)\digiCamControl\CameraControl.exe'
    )

    foreach ($path in $paths) {
        if (Test-Path -LiteralPath $path) {
            return $path
        }
    }

    return $null
}

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

$dccInstallerScript = Join-Path $repoRoot 'ops/install_digicamcontrol.ps1'
$installArgs = @(
    '-NoProfile',
    '-NonInteractive',
    '-ExecutionPolicy',
    'Bypass',
    '-File',
    $dccInstallerScript,
    '-SupervisorLog',
    $supervisorLog
)
$installOutput = @()
try {
    $installOutput = & powershell.exe @installArgs 2>&1
    $installExitCode = $LASTEXITCODE
} catch {
    $installExitCode = 1
    $installOutput = @('DCC_DOWNLOAD_FAILED')
}

if ($installExitCode -ne 0) {
    $state.status = 'ERROR'
    $state.last_heartbeat = (Get-Date).ToString('s')
    Save-PhotoboxState -Config $config -State $state

    $installCode = ($installOutput | ForEach-Object { [string]$_ } | Where-Object { -not [string]::IsNullOrWhiteSpace($_) } | Select-Object -Last 1)
    if ([string]::IsNullOrWhiteSpace($installCode)) {
        $installCode = 'DCC_DOWNLOAD_FAILED'
    }

    $msg = "digiCamControl fehlt oder Installation fehlgeschlagen: $installCode. Start abgebrochen, kein Restart-Loop."
    Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message $msg
    Write-Error $msg
    exit 1
}

try {
    Ensure-FirewallPort5513 -SupervisorLog $supervisorLog
} catch {
    $state.status = 'ERROR'
    $state.last_heartbeat = (Get-Date).ToString('s')
    Save-PhotoboxState -Config $config -State $state
    throw
}

$dccProcess = $null
foreach ($processName in @('digiCamControl', 'CameraControl')) {
    $dccProcess = Get-Process -Name $processName -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -ne $dccProcess) {
        break
    }
}
if ($null -eq $dccProcess) {
    $dccExe = Get-DigiCamControlExecutablePath
    if ([string]::IsNullOrWhiteSpace($dccExe)) {
        $state.status = 'ERROR'
        $state.last_heartbeat = (Get-Date).ToString('s')
        Save-PhotoboxState -Config $config -State $state

        $msg = 'digiCamControl/CameraControl EXE nicht gefunden. Start abgebrochen.'
        Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message $msg
        Write-Error $msg
        exit 1
    }

    $dccProcess = Start-Process -FilePath $dccExe -WindowStyle Minimized -PassThru
    Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "digiCamControl gestartet (PID $($dccProcess.Id))."
} else {
    Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "digiCamControl läuft bereits (PID $($dccProcess.Id))."
}

$dccReady = $false
for ($i = 0; $i -lt 10; $i++) {
    try {
        $sessionResponse = Invoke-WebRequest -Uri 'http://127.0.0.1:5513/session.json' -UseBasicParsing -TimeoutSec 2
        if ($sessionResponse.StatusCode -eq 200) {
            $dccReady = $true
            break
        }
    } catch {
    }
    Start-Sleep -Seconds 1
}

if (-not $dccReady) {
    $state.status = 'ERROR'
    $state.last_heartbeat = (Get-Date).ToString('s')
    Save-PhotoboxState -Config $config -State $state

    $msg = 'DCC_WEBSERVER_NOT_READY: digiCamControl Webserver nicht aktiv. Bitte in digiCamControl unter Settings -> Webserver -> Use web server aktivieren und digiCamControl neu starten. Start abgebrochen.'
    Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message $msg
    Write-Error $msg
    exit 1
}

$sessionFolder = 'E:\photobooth\data\watch'
$setFolderUrl = 'http://127.0.0.1:5513/?slc=set&param1=session.folder&param2=' + [uri]::EscapeDataString($sessionFolder)

try {
    $setFolderResponse = Invoke-WebRequest -Uri $setFolderUrl -UseBasicParsing -TimeoutSec 5
    $isOk = ($setFolderResponse.StatusCode -eq 200) -or ([string]$setFolderResponse.Content -match 'OK')
    if (-not $isOk) {
        throw 'invalid_response'
    }
} catch {
    $state.status = 'ERROR'
    $state.last_heartbeat = (Get-Date).ToString('s')
    Save-PhotoboxState -Config $config -State $state

    $msg = 'digiCamControl session.folder konnte nicht gesetzt werden.'
    Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message $msg
    Write-Error $msg
    exit 1
}

Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "digiCamControl session.folder gesetzt: $sessionFolder"

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


            if ($null -ne $watcherBundle) {
                Stop-PhotoboxWatcher -Bundle $watcherBundle
                $watcherBundle = $null
                $state.watcher_active = $false
                Write-PhotoboxLog -Path $supervisorLog -Level 'WARN' -Message 'Watcher wegen PHP-Ausfall gestoppt.'
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

            if ($null -eq $watcherBundle) {
                $watcherBundle = Start-PhotoboxWatcher -PhpExe $phpExe -Config $config -WatcherLog $watcherLog -LastImageMarker $lastImageMarker -WatcherErrorMarker $watcherErrorMarker
                Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message 'Watcher nach PHP-Neustart wieder gestartet.'
            }
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

        try {
            $printArgs = @() + $phpLaunchPlan.PhpPrefixArgs + @((Join-Path $repoRoot 'import/print_worker.php'), 'run')
            $printResult = & $phpExe @printArgs 2>&1
            $printText = (($printResult | ForEach-Object { [string]$_ }) -join ' | ').Trim()
            if ($LASTEXITCODE -eq 0) {
                if ($printText -ne '') {
                    Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message ("print_worker.php run: {0}" -f $printText)
                }
            } else {
                Write-PhotoboxLog -Path $supervisorLog -Level 'WARN' -Message ("print_worker.php run ExitCode={0}; Ausgabe: {1}" -f $LASTEXITCODE, $printText)
            }
        } catch {
            Write-PhotoboxLog -Path $supervisorLog -Level 'WARN' -Message ("print_worker.php run Fehler: {0}" -f $_.Exception.Message)
        }

        $nowUtc = [DateTime]::UtcNow
        if (($nowUtc - $lastCleanupRunAt).TotalMinutes -ge 60) {
            try {
                $cleanupArgs = @() + $phpLaunchPlan.PhpPrefixArgs + @((Join-Path $repoRoot 'import/import_service.php'), 'cleanup')
                $cleanupResult = & $phpExe @cleanupArgs 2>&1
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

    foreach ($procName in @('digiCamControl', 'CameraControl')) {
        $dccStopProc = Get-Process -Name $procName -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($null -ne $dccStopProc) {
            Stop-Process -Id $dccStopProc.Id -Force -ErrorAction SilentlyContinue
            Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "Supervisor-Shutdown hat $procName beendet (PID $($dccStopProc.Id))."
        }
    }

    $state.watcher_active = $false
    $state.last_heartbeat = (Get-Date).ToString('s')
    if ($state.status -eq 'RUNNING') {
        $state.status = 'STOPPED'
    }
    Save-PhotoboxState -Config $config -State $state
}
