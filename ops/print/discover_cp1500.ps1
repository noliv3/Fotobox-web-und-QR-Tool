param(
    [ValidateSet('detect', 'install')][string]$Mode = 'detect',
    [string]$IpAddress = ''
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$WarningPreference = 'SilentlyContinue'
$InformationPreference = 'SilentlyContinue'
$VerbosePreference = 'SilentlyContinue'
$OutputEncoding = [Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)

function Write-JsonAndExit {
    param([hashtable]$Data)
    Write-Output ($Data | ConvertTo-Json -Compress -Depth 6)
    exit 0
}

function Find-Cp1500Printers {
    param([array]$Printers)

    if ($null -eq $Printers) {
        return @()
    }

    return @(
        $Printers | Where-Object {
            ([string]$_.Name -like '*CP1500*') -or ([string]$_.DriverName -like '*CP1500*')
        }
    )
}

if (-not (Get-Command Get-Printer -ErrorAction SilentlyContinue)) {
    Write-JsonAndExit @{
        ok = $false
        mode = $Mode
        error = 'PRINT_CMDLETS_UNAVAILABLE'
        spoolerRunning = $null
        detectedNames = @()
        installedName = ''
        autoInstalled = $false
    }
}

$spoolerRunning = $false
try {
    $spooler = Get-Service -Name 'Spooler' -ErrorAction Stop
    $spoolerRunning = $spooler.Status -eq 'Running'
} catch {
    $spoolerRunning = $false
}

$printers = @(Get-Printer -ErrorAction SilentlyContinue)
$cp1500 = Find-Cp1500Printers -Printers $printers
$detectedNames = @($cp1500 | Select-Object -ExpandProperty Name -Unique)

if ($Mode -eq 'detect') {
    $online = $false
    if ($cp1500.Count -gt 0) {
        $online = -not [bool]$cp1500[0].WorkOffline
    }

    Write-JsonAndExit @{
        ok = $true
        mode = 'detect'
        error = ''
        spoolerRunning = $spoolerRunning
        detectedNames = $detectedNames
        installedName = if ($detectedNames.Count -gt 0) { [string]$detectedNames[0] } else { '' }
        autoInstalled = $false
        online = $online
    }
}

if ($IpAddress -notmatch '^\d{1,3}(\.\d{1,3}){3}$') {
    Write-JsonAndExit @{
        ok = $false
        mode = 'install'
        error = 'INVALID_IP'
        spoolerRunning = $spoolerRunning
        detectedNames = $detectedNames
        installedName = ''
        autoInstalled = $false
    }
}

if ($detectedNames.Count -gt 0) {
    Write-JsonAndExit @{
        ok = $true
        mode = 'install'
        error = ''
        spoolerRunning = $spoolerRunning
        detectedNames = $detectedNames
        installedName = [string]$detectedNames[0]
        autoInstalled = $false
    }
}

$installError = ''
$autoInstalled = $false
$targetName = "Canon SELPHY CP1500 ($IpAddress)"

try {
    $drivers = @()
    if (Get-Command Get-PrinterDriver -ErrorAction SilentlyContinue) {
        $drivers = @(Get-PrinterDriver -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Name)
    }

    $preferredDrivers = @(
        'Canon SELPHY CP1500',
        'Microsoft IPP Class Driver',
        'Canon SELPHY CP Series'
    )

    $driverName = ''
    foreach ($candidate in $preferredDrivers) {
        if ($drivers -contains $candidate) {
            $driverName = $candidate
            break
        }
    }

    if ($driverName -eq '') {
        throw [System.InvalidOperationException]::new('NO_DRIVER_AVAILABLE')
    }

    if ($driverName -eq 'Microsoft IPP Class Driver') {
        try {
            Add-Printer -Name $targetName -IppURL ("http://{0}:631/ipp/print" -f $IpAddress) -DriverName $driverName -ErrorAction Stop | Out-Null
        } catch {
            $portName = 'IP_' + $IpAddress
            if (-not (Get-PrinterPort -Name $portName -ErrorAction SilentlyContinue)) {
                Add-PrinterPort -Name $portName -PrinterHostAddress $IpAddress -ErrorAction Stop | Out-Null
            }
            Add-Printer -Name $targetName -PortName $portName -DriverName $driverName -ErrorAction Stop | Out-Null
        }
    } else {
        $portName = 'IP_' + $IpAddress
        if (-not (Get-PrinterPort -Name $portName -ErrorAction SilentlyContinue)) {
            Add-PrinterPort -Name $portName -PrinterHostAddress $IpAddress -ErrorAction Stop | Out-Null
        }
        Add-Printer -Name $targetName -PortName $portName -DriverName $driverName -ErrorAction Stop | Out-Null
    }

    $autoInstalled = $true
} catch {
    $installError = 'INSTALL_FAILED'
    if ($_.Exception -is [System.UnauthorizedAccessException]) {
        $installError = 'ACCESS_DENIED'
    } elseif ($_.Exception.Message -like '*NO_DRIVER_AVAILABLE*') {
        $installError = 'NO_DRIVER_AVAILABLE'
    }
}

$printers = @(Get-Printer -ErrorAction SilentlyContinue)
$cp1500 = Find-Cp1500Printers -Printers $printers
$detectedNames = @($cp1500 | Select-Object -ExpandProperty Name -Unique)
$installedName = if ($detectedNames.Count -gt 0) { [string]$detectedNames[0] } else { '' }

if ($installedName -eq '' -and $installError -eq '') {
    $installError = 'PRINTER_NOT_FOUND_AFTER_INSTALL'
}

Write-JsonAndExit @{
    ok = ($installedName -ne '')
    mode = 'install'
    error = $installError
    spoolerRunning = $spoolerRunning
    detectedNames = $detectedNames
    installedName = $installedName
    autoInstalled = $autoInstalled
}

