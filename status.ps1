Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'ops/photobox_ops.ps1')

$repoRoot = Get-RepoRoot
$config = Get-PhotoboxConfig -RepoRoot $repoRoot
$supervisorLog = Join-Path $config.logs_path 'supervisor.log'
$watcherLog = Join-Path $config.logs_path 'watcher.log'
$phpLog = Join-Path $config.logs_path 'php.log'

$state = Load-PhotoboxState -Config $config
$phpRunning = $false
$supervisorRunning = $false
$portReachable = $false

$phpConfigState = 'FAIL'
$sqliteState = 'FAIL'

try {
    $phpExe = Get-PhpExecutable -RepoRoot $repoRoot -SupervisorLog $supervisorLog
    $phpConfigResult = Test-PhpConfig -PhpExe $phpExe -PhpLog $phpLog -SupervisorLog $supervisorLog
    if ($phpConfigResult.Ok) {
        $phpConfigState = 'OK'
    }

    $sqliteResult = Test-SqliteSupport -PhpConfigResult $phpConfigResult -SupervisorLog $supervisorLog -PhpLog $phpLog
    if ($sqliteResult.Ok) {
        $sqliteState = 'OK'
    }
} catch {
    Write-PhotoboxLog -Path $supervisorLog -Level 'ERROR' -Message ("Status-Diagnose fehlgeschlagen: {0}" -f $_.Exception.Message)
}

if ($null -ne $state) {
    if ($state.php_pid) {
        $phpRunning = $null -ne (Get-Process -Id ([int]$state.php_pid) -ErrorAction SilentlyContinue)
    }
    if ($state.supervisor_pid) {
        $supervisorRunning = $null -ne (Get-Process -Id ([int]$state.supervisor_pid) -ErrorAction SilentlyContinue)
    }
}

try {
    $portReachable = Test-NetConnection -ComputerName '127.0.0.1' -Port ([int]$config.port) -InformationLevel Quiet -WarningAction SilentlyContinue
} catch {
    $portReachable = $false
}

Write-Host "Supervisor läuft: $supervisorRunning"
Write-Host "PHP läuft: $phpRunning"
Write-Host "Port $($config.port) erreichbar: $portReachable"
Write-Host "PHP config: $phpConfigState"
Write-Host "SQLite support: $sqliteState"

$watcherState = if ($null -ne $state) { $state.watcher_active } else { $false }
$heartbeat = if ($null -ne $state) { $state.last_heartbeat } else { 'n/a' }
$status = if ($null -ne $state -and $state.status) { $state.status } else { 'n/a' }

Write-Host "Watcher aktiv (letzter State): $watcherState"
Write-Host "Letzter Heartbeat: $heartbeat"
Write-Host "Supervisor Status: $status"

Write-Host ''
Write-Host 'Letzte Supervisor-Logzeilen:'
if (Test-Path -LiteralPath $supervisorLog) {
    Get-Content -LiteralPath $supervisorLog -Tail 5
}

Write-Host ''
Write-Host 'Letzte Watcher-Logzeilen:'
if (Test-Path -LiteralPath $watcherLog) {
    Get-Content -LiteralPath $watcherLog -Tail 5
}

Write-Host ''
Write-Host 'Letzte PHP-Logzeilen:'
if (Test-Path -LiteralPath $phpLog) {
    Get-Content -LiteralPath $phpLog -Tail 5
}
