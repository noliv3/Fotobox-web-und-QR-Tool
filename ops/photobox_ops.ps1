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

function Invoke-PhpCommand {
    param(
        [Parameter(Mandatory = $true)][string]$PhpExe,
        [Parameter(Mandatory = $true)][string[]]$Arguments
    )

    $output = @(& $PhpExe @Arguments 2>&1)
    $exitCode = $LASTEXITCODE

    return [pscustomobject]@{
        ExitCode = [int]$exitCode
        Output = ($output -join [Environment]::NewLine)
        Arguments = ($Arguments -join ' ')
    }
}

function Test-PhpConfig {
    param(
        [Parameter(Mandatory = $true)][string]$PhpExe,
        [Parameter(Mandatory = $true)][string]$PhpLog,
        [Parameter(Mandatory = $true)][string]$SupervisorLog
    )

    $commands = @(
        @('-v'),
        @('--ini'),
        @('-m')
    )

    $results = @{}
    $configOk = $true

    Write-PhotoboxLog -Path $PhpLog -Level 'INFO' -Message "PHP Diagnostik gestartet für: $PhpExe"

    foreach ($commandArgs in $commands) {
        $result = Invoke-PhpCommand -PhpExe $PhpExe -Arguments $commandArgs
        $key = $commandArgs[0]
        $results[$key] = $result

        Write-PhotoboxLog -Path $PhpLog -Level 'INFO' -Message "php $($result.Arguments) ExitCode=$($result.ExitCode)"
        if ([string]::IsNullOrWhiteSpace($result.Output)) {
            Write-PhotoboxLog -Path $PhpLog -Level 'INFO' -Message 'Keine Ausgabe.'
        } else {
            foreach ($line in ($result.Output -split "`r?`n")) {
                if ([string]::IsNullOrWhiteSpace($line)) {
                    continue
                }

                Write-PhotoboxLog -Path $PhpLog -Level 'INFO' -Message $line
            }
        }

        $hasParseIndicator = $result.Output -match 'Parse error' -or $result.Output -match 'Command line code'
        if ($result.ExitCode -ne 0 -or $hasParseIndicator) {
            $configOk = $false
        }
    }

    if (-not $configOk) {
        $help = @(
            'PHP Konfiguration FEHLERHAFT erkannt. Der Webserver startet nicht.',
            'Prüfe php.ini und zusätzliche INI-Dateien auf Syntax-/Parse-Fehler.',
            'Insbesondere Fehlermeldungen mit "Command line code" oder "Parse error" beheben.',
            'Nutze die oben geloggte Ausgabe von "php --ini" um die geladenen INI-Dateien zu prüfen.'
        ) -join ' '
        Write-PhotoboxLog -Path $SupervisorLog -Level 'ERROR' -Message $help
        Write-PhotoboxLog -Path $PhpLog -Level 'ERROR' -Message $help
    }

    return [pscustomobject]@{
        Ok = $configOk
        Version = $results['-v']
        Ini = $results['--ini']
        Modules = $results['-m']
    }
}

function Test-SqliteSupport {
    param(
        [Parameter(Mandatory = $true)]$PhpConfigResult,
        [Parameter(Mandatory = $true)][string]$SupervisorLog,
        [Parameter(Mandatory = $true)][string]$PhpLog
    )

    $moduleOutput = ''
    if ($null -ne $PhpConfigResult -and $null -ne $PhpConfigResult.Modules) {
        $moduleOutput = [string]$PhpConfigResult.Modules.Output
    }

    $hasPdoSqlite = $moduleOutput -match '(?im)^pdo_sqlite\s*$'
    $hasSqlite3 = $moduleOutput -match '(?im)^sqlite3\s*$'
    $ok = $hasPdoSqlite

    if (-not $ok) {
        $message = @(
            'SQLite Support FEHLT. Der Webserver startet nicht.',
            'Aktiviere in php.ini zwingend extension=pdo_sqlite (sqlite3 allein reicht für dieses Projekt nicht).',
            'Falls die Haupt-php.ini beschädigt ist, repariere sie oder nutze ein sauberes portables PHP unter runtime/php/.',
            'Die vollständige Ausgabe von "php --ini" und "php -m" steht in php.log.'
        ) -join ' '
        Write-PhotoboxLog -Path $SupervisorLog -Level 'ERROR' -Message $message
        Write-PhotoboxLog -Path $PhpLog -Level 'ERROR' -Message $message
    } else {
        Write-PhotoboxLog -Path $SupervisorLog -Level 'INFO' -Message ("SQLite Support erkannt (pdo_sqlite vorhanden, sqlite3_zusatz={0})." -f $hasSqlite3)
    }

    return [pscustomobject]@{
        Ok = $ok
        HasPdoSqlite = [bool]$hasPdoSqlite
        HasSqlite3 = [bool]$hasSqlite3
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

    $stdoutPath = Join-Path $Config.logs_path 'php.stdout.current.log'
    $stderrPath = Join-Path $Config.logs_path 'php.stderr.current.log'

    Set-Content -LiteralPath $stdoutPath -Value '' -Encoding UTF8
    Set-Content -LiteralPath $stderrPath -Value '' -Encoding UTF8

    $process = Start-Process -FilePath $PhpExe `
        -ArgumentList @("-S", "0.0.0.0:$($Config.port)", "-t", "web") `
        -WorkingDirectory $Config.repo_root `
        -PassThru `
        -WindowStyle Hidden `
        -RedirectStandardOutput $stdoutPath `
        -RedirectStandardError $stderrPath

    Write-PhotoboxLog -Path $PhpLog -Level 'INFO' -Message "PHP Server Start-Process PID=$($process.Id) Port=$($Config.port)"

    return [pscustomobject]@{
        Process = $process
        StdOutPath = $stdoutPath
        StdErrPath = $stderrPath
        StdOutOffset = 0
        StdErrOffset = 0
    }
}

function Read-TextFileShared {
    param([Parameter(Mandatory = $true)][string]$Path)

    $fileStream = $null
    $reader = $null

    try {
        $fileStream = New-Object System.IO.FileStream(
            $Path,
            [System.IO.FileMode]::Open,
            [System.IO.FileAccess]::Read,
            [System.IO.FileShare]::ReadWrite
        )
        $reader = New-Object System.IO.StreamReader($fileStream, [System.Text.Encoding]::UTF8, $true)
        return $reader.ReadToEnd()
    } finally {
        if ($null -ne $reader) {
            $reader.Dispose()
        } elseif ($null -ne $fileStream) {
            $fileStream.Dispose()
        }
    }
}

function Sync-PhpProcessLogs {
    param(
        [Parameter(Mandatory = $true)]$PhpRuntime,
        [Parameter(Mandatory = $true)][string]$PhpLog
    )

    $mutexName = 'Global\Photobox.SyncPhpProcessLogs'
    $syncMutex = $null
    $hasMutex = $false

    try {
        $syncMutex = New-Object System.Threading.Mutex($false, $mutexName)
        try {
            $hasMutex = $syncMutex.WaitOne(2000)
        } catch [System.Threading.AbandonedMutexException] {
            $hasMutex = $true
        }

        if (-not $hasMutex) {
            Write-PhotoboxLog -Path $PhpLog -Level 'WARN' -Message 'Log-Sync übersprungen: Mutex konnte nicht innerhalb von 2000ms übernommen werden.'
            return
        }

        foreach ($item in @(
            @{ Path = $PhpRuntime.StdOutPath; Key = 'StdOutOffset'; Stream = 'STDOUT' },
            @{ Path = $PhpRuntime.StdErrPath; Key = 'StdErrOffset'; Stream = 'STDERR' }
        )) {
            if (-not (Test-Path -LiteralPath $item.Path)) {
                continue
            }

            $raw = $null
            $readOk = $false
            $retryDelaysMs = @(100, 300, 800)

            for ($attempt = 0; $attempt -lt $retryDelaysMs.Count; $attempt++) {
                try {
                    $raw = Read-TextFileShared -Path $item.Path
                    $readOk = $true
                    break
                } catch [System.IO.IOException] {
                    $attemptHuman = $attempt + 1
                    if ($attempt -lt ($retryDelaysMs.Count - 1)) {
                        Start-Sleep -Milliseconds $retryDelaysMs[$attempt]
                    } else {
                        Write-PhotoboxLog -Path $PhpLog -Level 'WARN' -Message ("Log-Sync für {0} übersprungen: Datei {1} nach {2} Versuchen weiter gelockt ({3})." -f $item.Stream, $item.Path, $attemptHuman, $_.Exception.Message)
                    }
                } catch {
                    Write-PhotoboxLog -Path $PhpLog -Level 'WARN' -Message ("Log-Sync für {0} übersprungen: Unerwarteter Lesefehler bei {1} ({2})." -f $item.Stream, $item.Path, $_.Exception.Message)
                    break
                }
            }

            if (-not $readOk) {
                continue
            }

            $offset = [int]$PhpRuntime.($item.Key)
            if ($offset -lt 0) { $offset = 0 }
            if ($offset -gt $raw.Length) { $offset = 0 }

            if ($raw.Length -gt $offset) {
                $newContent = $raw.Substring($offset)
                foreach ($line in ($newContent -split "`r?`n")) {
                    if (-not [string]::IsNullOrWhiteSpace($line)) {
                        Write-PhotoboxLog -Path $PhpLog -Level $item.Stream -Message $line
                    }
                }
                $PhpRuntime.($item.Key) = $raw.Length
            }
        }
    } finally {
        if ($hasMutex -and $null -ne $syncMutex) {
            [void]$syncMutex.ReleaseMutex()
        }
        if ($null -ne $syncMutex) {
            $syncMutex.Dispose()
        }
    }
}

function Get-PhpLogTail {
    param(
        [Parameter(Mandatory = $true)][string]$PhpLog,
        [int]$Tail = 30
    )

    if (-not (Test-Path -LiteralPath $PhpLog)) {
        return @('php.log nicht vorhanden.')
    }

    return @(Get-Content -LiteralPath $PhpLog -Tail $Tail)
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
