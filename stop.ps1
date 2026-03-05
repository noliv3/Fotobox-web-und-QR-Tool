Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'ops/photobox_ops.ps1')

$repoRoot = Get-RepoRoot
$config = Get-PhotoboxConfig -RepoRoot $repoRoot
$supervisorLog = Join-Path $config.logs_path 'supervisor.log'

$state = Load-PhotoboxState -Config $config
if ($null -eq $state) {
    Write-Host 'Kein Supervisor-Status gefunden (supervisor_state.json fehlt).'
    exit 0
}

if ($state.php_pid) {
    $phpProc = Get-Process -Id ([int]$state.php_pid) -ErrorAction SilentlyContinue
    if ($null -ne $phpProc) {
        Stop-Process -Id $phpProc.Id -Force -ErrorAction SilentlyContinue
        Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "stop.ps1 hat PHP beendet (PID $($phpProc.Id))."
    }
}

if ($state.supervisor_pid) {
    $supProc = Get-Process -Id ([int]$state.supervisor_pid) -ErrorAction SilentlyContinue
    if ($null -ne $supProc -and $supProc.Id -ne $PID) {
        Stop-Process -Id $supProc.Id -Force -ErrorAction SilentlyContinue
        Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "stop.ps1 hat Supervisor beendet (PID $($supProc.Id))."
    }
}


foreach ($procName in @('digiCamControl', 'CameraControl')) {
    $dccProc = Get-Process -Name $procName -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -ne $dccProc) {
        Stop-Process -Id $dccProc.Id -Force -ErrorAction SilentlyContinue
        Write-PhotoboxLog -Path $supervisorLog -Level 'INFO' -Message "stop.ps1 hat $procName beendet (PID $($dccProc.Id))."
    }
}

$newState = @{
    supervisor_pid = 0
    php_pid = 0
    started_at = $state.started_at
    watcher_active = $false
    last_heartbeat = (Get-Date).ToString('s')
    port = $state.port
}
Save-PhotoboxState -Config $config -State $newState

Write-Host 'Photobox Dienste gestoppt (best-effort).'
