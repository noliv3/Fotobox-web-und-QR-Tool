param(
    [Parameter(Mandatory = $true)][string]$PrinterName
)

$spooler = Get-Service -Name 'Spooler' -ErrorAction SilentlyContinue
$spoolerRunning = $null -ne $spooler -and $spooler.Status -eq 'Running'

if (-not $spoolerRunning) {
    [pscustomobject]@{
        ok = $false
        spoolerRunning = $false
        online = $false
        paused = $false
        errorState = 'SPOOLER_STOPPED'
        queueCount = 0
    } | ConvertTo-Json -Depth 4 -Compress
    exit 0
}

$printer = Get-Printer -Name $PrinterName -ErrorAction SilentlyContinue
if ($null -eq $printer) {
    [pscustomobject]@{
        ok = $false
        spoolerRunning = $true
        online = $false
        paused = $false
        errorState = 'PRINTER_NOT_FOUND'
        queueCount = 0
    } | ConvertTo-Json -Depth 4 -Compress
    exit 0
}

$jobs = @(Get-PrintJob -PrinterName $PrinterName -ErrorAction SilentlyContinue)
$queueCount = ($jobs | Measure-Object).Count

[pscustomobject]@{
    ok = $true
    spoolerRunning = $true
    online = (-not [bool]$printer.WorkOffline)
    paused = [bool]$printer.Paused
    errorState = [string]($printer.PrinterStatus)
    queueCount = [int]$queueCount
} | ConvertTo-Json -Depth 4 -Compress
exit 0
