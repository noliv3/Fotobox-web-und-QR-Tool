Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

param(
    [Parameter(Mandatory = $true)][string]$SupervisorLog
)

function Write-PhaseLog {
    param([Parameter(Mandatory = $true)][string]$Message)

    $timestamp = (Get-Date).ToString('s')
    Add-Content -LiteralPath $SupervisorLog -Value ("{0} [INFO] {1}" -f $timestamp, $Message)
}

function Exit-WithCode {
    param(
        [Parameter(Mandatory = $true)][int]$ExitCode,
        [Parameter(Mandatory = $true)][string]$ErrorCode
    )

    Write-Output $ErrorCode
    exit $ExitCode
}

function Test-DigiCamControlInstalled {
    $global:DccExe = $null
    $global:DccRemoteExe = $null

    $knownExePaths = @(
        'C:\Program Files\digiCamControl\digiCamControl.exe',
        'C:\Program Files (x86)\digiCamControl\digiCamControl.exe'
    )

    foreach ($path in $knownExePaths) {
        if (Test-Path -LiteralPath $path) {
            $global:DccExe = $path
            $candidateRemote = Join-Path (Split-Path -Path $path -Parent) 'CameraControlRemoteCmd.exe'
            if (Test-Path -LiteralPath $candidateRemote) {
                $global:DccRemoteExe = $candidateRemote
            }
            return $true
        }
    }

    $uninstallRoots = @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
        'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
    )

    foreach ($root in $uninstallRoots) {
        $entry = Get-ItemProperty -Path $root -ErrorAction SilentlyContinue | Where-Object {
            ([string]$_.DisplayName) -like '*digiCamControl*'
        } | Select-Object -First 1

        if ($null -eq $entry) {
            continue
        }

        $installLocation = [string]$entry.InstallLocation
        if (-not [string]::IsNullOrWhiteSpace($installLocation)) {
            $exeFromInstallLocation = Join-Path $installLocation 'digiCamControl.exe'
            if (Test-Path -LiteralPath $exeFromInstallLocation) {
                $global:DccExe = $exeFromInstallLocation
            }

            $remoteFromInstallLocation = Join-Path $installLocation 'CameraControlRemoteCmd.exe'
            if (Test-Path -LiteralPath $remoteFromInstallLocation) {
                $global:DccRemoteExe = $remoteFromInstallLocation
            }
        }

        $displayIcon = [string]$entry.DisplayIcon
        if ([string]::IsNullOrWhiteSpace($global:DccExe) -and -not [string]::IsNullOrWhiteSpace($displayIcon)) {
            $candidateExe = ($displayIcon -replace '"', '').Split(',')[0]
            if (Test-Path -LiteralPath $candidateExe) {
                $global:DccExe = $candidateExe
            }
        }

        if ([string]::IsNullOrWhiteSpace($global:DccExe)) {
            foreach ($knownPath in $knownExePaths) {
                if (Test-Path -LiteralPath $knownPath) {
                    $global:DccExe = $knownPath
                    $candidateRemote = Join-Path (Split-Path -Path $knownPath -Parent) 'CameraControlRemoteCmd.exe'
                    if (Test-Path -LiteralPath $candidateRemote) {
                        $global:DccRemoteExe = $candidateRemote
                    }
                    break
                }
            }
        }

        return $true
    }

    return $false
}

if (Test-DigiCamControlInstalled) {
    Write-PhaseLog -Message 'digiCamControl: installed'
    exit 0
}

$downloadDir = 'E:\photobooth\runtime\downloads'
$installerPath = Join-Path $downloadDir 'digiCamControlsetup_2.1.7.exe'
$tmpInstallerPath = Join-Path $downloadDir 'digiCamControlsetup_2.1.7.exe.part'
$minInstallerBytes = 20MB
$downloadSucceeded = $false
$downloadUrls = @(
    'https://sourceforge.net/projects/digicamcontrol/files/digiCamControlsetup_2.1.7.exe/download',
    'https://sourceforge.net/projects/digicamcontrol/files/digiCamControlsetup_2.1.7.0.exe/download'
)

if (-not (Test-Path -LiteralPath $downloadDir)) {
    New-Item -Path $downloadDir -ItemType Directory -Force | Out-Null
}

$hasLocalInstaller = Test-Path -LiteralPath $installerPath

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

foreach ($downloadUrl in $downloadUrls) {
    try {
        if (Test-Path -LiteralPath $tmpInstallerPath) {
            Remove-Item -LiteralPath $tmpInstallerPath -Force -ErrorAction SilentlyContinue
        }

        Invoke-WebRequest -Uri $downloadUrl -OutFile $tmpInstallerPath -UseBasicParsing -MaximumRedirection 10 -TimeoutSec 180

        if (-not (Test-Path -LiteralPath $tmpInstallerPath)) {
            continue
        }

        $size = (Get-Item -LiteralPath $tmpInstallerPath).Length
        if ($size -le $minInstallerBytes) {
            continue
        }

        Move-Item -LiteralPath $tmpInstallerPath -Destination $installerPath -Force

        $downloadSucceeded = $true
        break
    } catch {
    }
}

if (Test-Path -LiteralPath $tmpInstallerPath) {
    Remove-Item -LiteralPath $tmpInstallerPath -Force -ErrorAction SilentlyContinue
}

if (-not $downloadSucceeded) {
    if (-not (Test-Path -LiteralPath $installerPath)) {
        if ($hasLocalInstaller) {
            Write-PhaseLog -Message 'digiCamControl: download_failed_using_cached_installer'
        } else {
            Exit-WithCode -ExitCode 2 -ErrorCode 'DCC_DOWNLOAD_FAILED_OFFLINE'
        }
    } else {
        $existingSize = (Get-Item -LiteralPath $installerPath).Length
        if ($existingSize -le $minInstallerBytes) {
            if ($hasLocalInstaller) {
                Write-PhaseLog -Message 'digiCamControl: download_invalid_using_cached_installer'
            } else {
                Exit-WithCode -ExitCode 2 -ErrorCode 'DCC_DOWNLOAD_FAILED'
            }
        }
    }
}

try {
    $args = @('/SP-', '/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART')
    $p = Start-Process -FilePath $installerPath -ArgumentList $args -Wait -PassThru
    if ($p.ExitCode -ne 0) {
        Exit-WithCode -ExitCode 3 -ErrorCode ("DCC_INSTALL_EXITCODE_{0}" -f $p.ExitCode)
    }
} catch {
    Exit-WithCode -ExitCode 3 -ErrorCode 'DCC_INSTALL_EXITCODE_EXCEPTION'
}

if (-not (Test-DigiCamControlInstalled)) {
    Exit-WithCode -ExitCode 4 -ErrorCode 'DCC_INSTALL_NOT_DETECTED'
}

Write-PhaseLog -Message 'digiCamControl: installed'
exit 0
