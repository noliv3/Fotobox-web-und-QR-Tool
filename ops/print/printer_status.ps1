param(
    [Parameter(Mandatory = $true)][string]$PrinterName
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$WarningPreference = 'SilentlyContinue'
$InformationPreference = 'SilentlyContinue'
$VerbosePreference = 'SilentlyContinue'
$OutputEncoding = [Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)

try {
    $spooler = Get-Service -Name 'Spooler' -ErrorAction Stop
    if ($spooler.Status -ne 'Running') {
        Write-Output (@{ ok = $false; online = $false; paused = $false; errorState = 'SPOOLER_STOPPED'; queueCount = 0; error = 'SPOOLER_STOPPED' } | ConvertTo-Json -Compress)
        exit 0
    }

    $printer = Get-Printer -Name $PrinterName -ErrorAction Stop
    $jobs = @(Get-PrintJob -PrinterName $PrinterName -ErrorAction SilentlyContinue)

    Write-Output (@{
            ok = $true
            online = (-not [bool]$printer.WorkOffline)
            paused = [bool]$printer.Paused
            errorState = [string]($printer.PrinterStatus)
            queueCount = [int]$jobs.Count
        } | ConvertTo-Json -Compress)
    exit 0
}
catch {
    $code = 'PS_EXCEPTION'
    if ($_.FullyQualifiedErrorId -like '*ServiceCommandException*') {
        $code = 'SPOOLER_STOPPED'
    } elseif ($_.FullyQualifiedErrorId -like '*GetPrinter*' -or $_.CategoryInfo.Reason -eq 'CimJobException') {
        $code = 'PRINTER_NOT_FOUND'
    }

    Write-Output (@{ ok = $false; online = $false; paused = $false; errorState = $code; queueCount = 0; error = $code } | ConvertTo-Json -Compress)
    exit 0
}
