param(
    [Parameter(Mandatory = $true)][string]$PrinterName,
    [Parameter(Mandatory = $true)][int]$JobId
)

$job = Get-PrintJob -PrinterName $PrinterName -ID $JobId -ErrorAction SilentlyContinue
if ($null -eq $job) {
    [pscustomobject]@{
        ok = $true
        exists = $false
        state = ''
        flags = @()
    } | ConvertTo-Json -Depth 4 -Compress
    exit 0
}

$flags = @()
if ($null -ne $job.JobStatus) {
    $flags = @([string]$job.JobStatus)
}

[pscustomobject]@{
    ok = $true
    exists = $true
    state = [string]$job.JobStatus
    flags = $flags
} | ConvertTo-Json -Depth 4 -Compress
exit 0
