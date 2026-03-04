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

function Test-DigiCamControlInstalled {
    $knownCmdPaths = @(
        'C:\Program Files\digiCamControl\CameraControlRemoteCmd.exe',
        'C:\Program Files (x86)\digiCamControl\CameraControlRemoteCmd.exe'
    )

    foreach ($path in $knownCmdPaths) {
        if (Test-Path -LiteralPath $path) {
            return $true
        }
    }

    $uninstallRoots = @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
        'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*',
        'HKCU:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*'
    )

    foreach ($root in $uninstallRoots) {
        $entry = Get-ItemProperty -Path $root -ErrorAction SilentlyContinue | Where-Object {
            $displayName = [string]$_.DisplayName
            -not [string]::IsNullOrWhiteSpace($displayName) -and $displayName -match 'digiCamControl'
        } | Select-Object -First 1

        if ($null -ne $entry) {
            return $true
        }
    }

    return $false
}

if (Test-DigiCamControlInstalled) {
    Write-PhaseLog -Message 'digiCamControl: installed'
    exit 0
}

$downloadUrl = 'https://sourceforge.net/projects/digicamcontrol/files/digiCamControlsetup_2.1.7.0.exe/download'
$downloadDir = 'E:\photobooth\runtime\downloads'
$installerPath = Join-Path $downloadDir 'digiCamControlsetup_2.1.7.0.exe'

try {
    if (-not (Test-Path -LiteralPath $downloadDir)) {
        New-Item -Path $downloadDir -ItemType Directory -Force | Out-Null
    }

    Invoke-WebRequest -Uri $downloadUrl -OutFile $installerPath -UseBasicParsing -TimeoutSec 120
} catch {
    Write-PhaseLog -Message 'digiCamControl: download_failed'
    exit 1
}

try {
    $args = @(
        '/SP-',
        '/VERYSILENT',
        '/SUPPRESSMSGBOXES',
        '/NORESTART',
        '/DIR="C:\Program Files\digiCamControl"'
    )

    $proc = Start-Process -FilePath $installerPath -ArgumentList $args -Wait -PassThru
    if ($proc.ExitCode -ne 0) {
        Write-PhaseLog -Message 'digiCamControl: install_failed'
        exit 1
    }

    if (-not (Test-DigiCamControlInstalled)) {
        Write-PhaseLog -Message 'digiCamControl: install_failed'
        exit 1
    }

    Write-PhaseLog -Message 'digiCamControl: installed'
    exit 0
} catch {
    Write-PhaseLog -Message 'digiCamControl: install_failed'
    exit 1
}
