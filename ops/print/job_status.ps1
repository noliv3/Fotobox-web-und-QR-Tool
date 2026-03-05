param(
    [Parameter(Mandatory = $true)][string]$PrinterName,
    [Parameter(Mandatory = $true)][int]$JobId
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
        Write-Output (@{ exists = $false; state = ''; flags = @(); error = 'SPOOLER_STOPPED' } | ConvertTo-Json -Compress)
        exit 0
    }

    $job = Get-PrintJob -PrinterName $PrinterName -ID $JobId -ErrorAction SilentlyContinue
    if ($null -eq $job) {
        Write-Output (@{ exists = $false; state = ''; flags = @() } | ConvertTo-Json -Compress)
        exit 0
    }

    $state = [string]($job.JobStatus)
    $flags = if ([string]::IsNullOrWhiteSpace($state)) { @() } else { @($state) }

    Write-Output (@{ exists = $true; state = $state; flags = $flags } | ConvertTo-Json -Compress)
    exit 0
}
catch {
    $code = 'PS_EXCEPTION'
    if ($_.FullyQualifiedErrorId -like '*GetPrinter*' -or $_.CategoryInfo.Reason -eq 'CimJobException') {
        $code = 'PRINTER_NOT_FOUND'
    } elseif ($_.FullyQualifiedErrorId -like '*ServiceCommandException*') {
        $code = 'SPOOLER_STOPPED'
    }

    Write-Output (@{ exists = $false; state = ''; flags = @(); error = $code } | ConvertTo-Json -Compress)
    exit 0
}
