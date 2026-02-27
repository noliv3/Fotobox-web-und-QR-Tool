Set-StrictMode -Version 2.0

. (Join-Path $PSScriptRoot 'config.ps1')

function Get-RepoRoot {
    return [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
}

function Write-PhotoboxLog {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Level,
        [Parameter(Mandatory = $true)][string]$Message
    )

    $timestamp = (Get-Date).ToString('s')
    Add-Content -LiteralPath $Path -Value ("{0} [{1}] {2}" -f $timestamp, $Level.ToUpperInvariant(), $Message)
}

function Ensure-PhotoboxDirectory {
    param([Parameter(Mandatory = $true)][string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -Path $Path -ItemType Directory -Force | Out-Null
    }
}

function Test-PhotoboxAdmin {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltinRole]::Administrator)
}

function Get-PhotoboxStatePath {
    param([Parameter(Mandatory = $true)][pscustomobject]$Config)
    return Join-Path $Config.logs_path 'supervisor_state.json'
}

function Save-PhotoboxState {
    param(
        [Parameter(Mandatory = $true)][pscustomobject]$Config,
        [Parameter(Mandatory = $true)][hashtable]$State
    )

    $path = Get-PhotoboxStatePath -Config $Config
    ($State | ConvertTo-Json -Depth 6) | Set-Content -LiteralPath $path -Encoding UTF8
}

function Load-PhotoboxState {
    param([Parameter(Mandatory = $true)][pscustomobject]$Config)

    $path = Get-PhotoboxStatePath -Config $Config
    if (-not (Test-Path -LiteralPath $path)) {
        return $null
    }

    try {
        return Get-Content -LiteralPath $path -Raw | ConvertFrom-Json
    } catch {
        return $null
    }
}

function Get-PhpExecutable {
    param(
        [Parameter(Mandatory = $true)][string]$RepoRoot,
        [Parameter(Mandatory = $true)][string]$SupervisorLog
    )

    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($null -ne $cmd) {
        return $cmd.Source
    }

    $portable = Join-Path $RepoRoot 'runtime/php/php.exe'
    if (Test-Path -LiteralPath $portable) {
        return $portable
    }

    $runtimeRoot = Join-Path $RepoRoot 'runtime/php'
    Ensure-PhotoboxDirectory -Path $runtimeRoot

    if (-not (Test-InternetConnectivity)) {
        $msg = "PHP nicht gefunden. Lege portable PHP unter runtime/php/php.exe ab. Optionaler Download übersprungen (kein Internet)."
        Write-PhotoboxLog -Path $SupervisorLog -Level 'ERROR' -Message $msg
        throw $msg
    }

    $downloaded = Install-PortablePhp -RepoRoot $RepoRoot -SupervisorLog $SupervisorLog
    if ($downloaded -and (Test-Path -LiteralPath $portable)) {
        return $portable
    }

    $msg = "PHP nicht gefunden. Lege portable PHP unter runtime/php/php.exe ab."
    Write-PhotoboxLog -Path $SupervisorLog -Level 'ERROR' -Message $msg
    throw $msg
}

function Test-InternetConnectivity {
    try {
        $result = Test-NetConnection -ComputerName 'windows.php.net' -Port 443 -InformationLevel Quiet -WarningAction SilentlyContinue
        return [bool]$result
    } catch {
        return $false
    }
}

function Install-PortablePhp {
    param(
        [Parameter(Mandatory = $true)][string]$RepoRoot,
        [Parameter(Mandatory = $true)][string]$SupervisorLog
    )

    $zipUrl = 'https://windows.php.net/downloads/releases/php-8.3.16-Win32-vs16-x64.zip'
    $zipTarget = Join-Path $RepoRoot 'runtime/php/php-portable.zip'
    $extractTarget = Join-Path $RepoRoot 'runtime/php'

    try {
        Write-PhotoboxLog -Path $SupervisorLog -Level 'INFO' -Message "Versuche optionalen PHP-Download: $zipUrl"
        Invoke-WebRequest -Uri $zipUrl -OutFile $zipTarget -UseBasicParsing -TimeoutSec 30
        Expand-Archive -Path $zipTarget -DestinationPath $extractTarget -Force
        Remove-Item -LiteralPath $zipTarget -Force -ErrorAction SilentlyContinue
        Write-PhotoboxLog -Path $SupervisorLog -Level 'INFO' -Message 'Portable PHP wurde heruntergeladen und entpackt.'
        return $true
    } catch {
        Write-PhotoboxLog -Path $SupervisorLog -Level 'ERROR' -Message ("Optionaler PHP-Download fehlgeschlagen: {0}" -f $_.Exception.Message)
        return $false
    }
}

function Test-PortAvailable {
    param([Parameter(Mandatory = $true)][int]$Port)

    $listeners = Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object { $_.LocalPort -eq $Port }
    return $null -eq $listeners
}

function Ensure-FirewallRule {
    param(
        [Parameter(Mandatory = $true)][int]$Port,
        [Parameter(Mandatory = $true)][string]$SupervisorLog
    )

    $ruleName = "Photobox HTTP Port $Port"
    $exists = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    if ($null -ne $exists) {
        Write-PhotoboxLog -Path $SupervisorLog -Level 'INFO' -Message "Firewall-Regel bereits vorhanden: $ruleName"
        return
    }

    if (Test-PhotoboxAdmin) {
        New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Action Allow -Protocol TCP -LocalPort $Port | Out-Null
        Write-PhotoboxLog -Path $SupervisorLog -Level 'INFO' -Message "Firewall-Regel erstellt: $ruleName"
        return
    }

    $command = "New-NetFirewallRule -DisplayName '$ruleName' -Direction Inbound -Action Allow -Protocol TCP -LocalPort $Port"
    Write-PhotoboxLog -Path $SupervisorLog -Level 'WARN' -Message "Keine Admin-Rechte. Als Admin ausführen: $command"
    Write-Host "[WARN] Keine Admin-Rechte. Bitte als Administrator ausführen:"
    Write-Host $command
}

function Test-PrinterStatus {
    param(
        [Parameter(Mandatory = $true)][pscustomobject]$Config,
        [Parameter(Mandatory = $true)][string]$SupervisorLog
    )

    try {
        $printers = Get-Printer -ErrorAction Stop
    } catch {
        Write-PhotoboxLog -Path $SupervisorLog -Level 'WARN' -Message 'Druckerprüfung nicht möglich (Get-Printer fehlgeschlagen).'
        return
    }

    $configuredName = [string]$Config.printer_name
    if (-not [string]::IsNullOrWhiteSpace($configuredName)) {
        $exact = $printers | Where-Object { $_.Name -eq $configuredName }
        if ($null -eq $exact) {
            Write-PhotoboxLog -Path $SupervisorLog -Level 'ERROR' -Message "Konfigurierter Drucker fehlt: $configuredName"
        } else {
            Write-PhotoboxLog -Path $SupervisorLog -Level 'INFO' -Message "Drucker gefunden: $configuredName (Status: $($exact.PrinterStatus))"
        }
        return
    }

    $online = $printers | Where-Object { $_.PrinterStatus -ne 'Offline' }
    if ($online.Count -gt 0) {
        Write-PhotoboxLog -Path $SupervisorLog -Level 'INFO' -Message "Mindestens ein Drucker verfügbar: $($online[0].Name)"
    } else {
        Write-PhotoboxLog -Path $SupervisorLog -Level 'WARN' -Message 'Kein online Drucker gefunden (best-effort Check).'
    }
}

function Test-CameraStatus {
    param([Parameter(Mandatory = $true)][string]$SupervisorLog)

    try {
        $devices = Get-PnpDevice -ErrorAction Stop | Where-Object {
            $_.FriendlyName -match 'Canon|EOS' -or $_.Class -match 'Imaging|Camera'
        }
        if ($devices.Count -gt 0) {
            Write-PhotoboxLog -Path $SupervisorLog -Level 'INFO' -Message ("Kamera-Hinweis: {0}" -f ($devices[0].FriendlyName))
        } else {
            Write-PhotoboxLog -Path $SupervisorLog -Level 'WARN' -Message 'Keine Kamera per PnP erkannt (best-effort).'
        }
    } catch {
        Write-PhotoboxLog -Path $SupervisorLog -Level 'WARN' -Message 'Kameraprüfung via Get-PnpDevice fehlgeschlagen (best-effort).'
    }
}

function Test-WatchPathWritable {
    param(
        [Parameter(Mandatory = $true)][string]$WatchPath,
        [Parameter(Mandatory = $true)][string]$SupervisorLog
    )

    if (-not (Test-Path -LiteralPath $WatchPath)) {
        Write-PhotoboxLog -Path $SupervisorLog -Level 'ERROR' -Message "Watch-Ordner fehlt: $WatchPath"
        throw "Watch-Ordner fehlt: $WatchPath"
    }

    $probe = Join-Path $WatchPath (".write-test-{0}.tmp" -f [guid]::NewGuid().ToString('N'))
    try {
        Set-Content -LiteralPath $probe -Value 'ok' -Encoding ASCII
        Remove-Item -LiteralPath $probe -Force
        Write-PhotoboxLog -Path $SupervisorLog -Level 'INFO' -Message "Watch-Ordner ist schreibbar: $WatchPath"
    } catch {
        $msg = "Watch-Ordner nicht schreibbar: $WatchPath"
        Write-PhotoboxLog -Path $SupervisorLog -Level 'ERROR' -Message $msg
        throw $msg
    }
}

function Start-PhotoboxPhpServer {
    param(
        [Parameter(Mandatory = $true)][string]$PhpExe,
        [Parameter(Mandatory = $true)][pscustomobject]$Config,
        [Parameter(Mandatory = $true)][string]$PhpLog
    )

    $command = '"{0}" -S 0.0.0.0:{1} -t web >> "{2}" 2>>&1' -f $PhpExe, $Config.port, $PhpLog
    $process = Start-Process -FilePath 'cmd.exe' -ArgumentList '/c', $command -WorkingDirectory $Config.repo_root -PassThru -WindowStyle Hidden
    return $process
}

function Wait-FileReady {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [int]$MaxWaitSeconds = 30
    )

    $deadline = (Get-Date).AddSeconds($MaxWaitSeconds)
    $lastSize = -1

    while ((Get-Date) -lt $deadline) {
        if (-not (Test-Path -LiteralPath $Path)) {
            Start-Sleep -Milliseconds 300
            continue
        }

        try {
            $file = Get-Item -LiteralPath $Path -ErrorAction Stop
            $size = $file.Length
            $stream = [System.IO.File]::Open($Path, 'Open', 'Read', 'ReadWrite')
            $stream.Close()

            if ($size -gt 0 -and $size -eq $lastSize) {
                return $true
            }

            $lastSize = $size
        } catch {
            # warten
        }

        Start-Sleep -Milliseconds 500
    }

    return $false
}

function Start-PhotoboxWatcher {
    param(
        [Parameter(Mandatory = $true)][string]$PhpExe,
        [Parameter(Mandatory = $true)][pscustomobject]$Config,
        [Parameter(Mandatory = $true)][string]$WatcherLog,
        [Parameter(Mandatory = $true)][string]$LastImageMarker
    )

    $watcher = New-Object System.IO.FileSystemWatcher
    $watcher.Path = $Config.watch_path
    $watcher.IncludeSubdirectories = $false
    $watcher.Filter = '*.*'
    $watcher.EnableRaisingEvents = $true

    $action = {
        $path = $Event.SourceEventArgs.FullPath
        $name = $Event.SourceEventArgs.Name
        $ext = [System.IO.Path]::GetExtension($name)
        if ($ext -notin @('.jpg', '.jpeg', '.JPG', '.JPEG')) {
            return
        }

        $logPath = $Event.MessageData.WatcherLog
        $phpExe = $Event.MessageData.PhpExe
        $repoRoot = $Event.MessageData.RepoRoot
        $marker = $Event.MessageData.LastImageMarker

        $ts = (Get-Date).ToString('s')
        Add-Content -LiteralPath $logPath -Value "$ts [INFO] Event erkannt: $name"

        $ready = $false
        $deadline = (Get-Date).AddSeconds(30)
        $lastSize = -1
        while ((Get-Date) -lt $deadline) {
            try {
                $item = Get-Item -LiteralPath $path -ErrorAction Stop
                $size = $item.Length
                $stream = [System.IO.File]::Open($path, 'Open', 'Read', 'ReadWrite')
                $stream.Close()

                if ($size -gt 0 -and $size -eq $lastSize) {
                    $ready = $true
                    break
                }

                $lastSize = $size
            } catch {
            }
            Start-Sleep -Milliseconds 500
        }

        if (-not $ready) {
            $ts = (Get-Date).ToString('s')
            Add-Content -LiteralPath $logPath -Value "$ts [WARN] Datei nicht bereit: $path"
            return
        }

        $ts = (Get-Date).ToString('s')
        Add-Content -LiteralPath $logPath -Value "$ts [INFO] Starte ingest-file: $path"
        & $phpExe 'import/import_service.php' 'ingest-file' $path 2>&1 | ForEach-Object {
            $lineTs = (Get-Date).ToString('s')
            Add-Content -LiteralPath $logPath -Value "$lineTs [INFO] $_"
        }

        Set-Content -LiteralPath $marker -Value ([DateTimeOffset]::UtcNow.ToUnixTimeSeconds()) -Encoding ASCII
    }

    $messageData = @{
        WatcherLog = $WatcherLog
        PhpExe = $PhpExe
        RepoRoot = $Config.repo_root
        LastImageMarker = $LastImageMarker
    }

    $createdSub = Register-ObjectEvent -InputObject $watcher -EventName Created -SourceIdentifier 'PhotoboxWatcherCreated' -Action $action -MessageData $messageData
    $renamedSub = Register-ObjectEvent -InputObject $watcher -EventName Renamed -SourceIdentifier 'PhotoboxWatcherRenamed' -Action $action -MessageData $messageData

    return @{ Watcher = $watcher; Created = $createdSub; Renamed = $renamedSub }
}

function Stop-PhotoboxWatcher {
    param([Parameter(Mandatory = $true)]$Bundle)

    foreach ($id in @('PhotoboxWatcherCreated', 'PhotoboxWatcherRenamed')) {
        Unregister-Event -SourceIdentifier $id -ErrorAction SilentlyContinue
        Get-Job -Name $id -ErrorAction SilentlyContinue | Remove-Job -Force -ErrorAction SilentlyContinue
    }

    if ($null -ne $Bundle -and $null -ne $Bundle.Watcher) {
        $Bundle.Watcher.EnableRaisingEvents = $false
        $Bundle.Watcher.Dispose()
    }
}

function Get-PendingPrintJobsCount {
    param(
        [Parameter(Mandatory = $true)][string]$PhpExe,
        [Parameter(Mandatory = $true)][pscustomobject]$Config
    )

    if (-not (Test-Path -LiteralPath $Config.db_path)) {
        return 0
    }

    $code = '$db=$argv[1];$pdo=new PDO("sqlite:$db");$c=$pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status = ''pending''")->fetchColumn();echo (int)$c;'
    try {
        $result = & $PhpExe '-r' $code $Config.db_path
        return [int]$result
    } catch {
        return 0
    }
}
