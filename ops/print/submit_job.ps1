param(
    [Parameter(Mandatory = $true)][string]$PrinterName,
    [Parameter(Mandatory = $true)][string]$File,
    [Parameter(Mandatory = $true)][string]$DocumentName
)

$spooler = Get-Service -Name 'Spooler' -ErrorAction SilentlyContinue
if ($null -eq $spooler -or $spooler.Status -ne 'Running') {
    [pscustomobject]@{ ok = $false; error = 'SPOOLER_STOPPED' } | ConvertTo-Json -Compress
    exit 0
}

$printer = Get-Printer -Name $PrinterName -ErrorAction SilentlyContinue
if ($null -eq $printer) {
    [pscustomobject]@{ ok = $false; error = 'PRINTER_NOT_FOUND' } | ConvertTo-Json -Compress
    exit 0
}

if (-not (Test-Path -LiteralPath $File)) {
    [pscustomobject]@{ ok = $false; error = 'PRINTFILE_MISSING' } | ConvertTo-Json -Compress
    exit 0
}

try {
    Add-Type -AssemblyName System.Drawing
    Add-Type -AssemblyName System.Windows.Forms

    $printDoc = New-Object System.Drawing.Printing.PrintDocument
    $printDoc.PrinterSettings.PrinterName = $PrinterName
    $printDoc.DocumentName = $DocumentName

    $printDoc.add_PrintPage({
        param($sender, $e)
        $img = [System.Drawing.Image]::FromFile($File)
        try {
            $bounds = $e.MarginBounds
            $ratio = [Math]::Min($bounds.Width / $img.Width, $bounds.Height / $img.Height)
            $w = [int]([Math]::Floor($img.Width * $ratio))
            $h = [int]([Math]::Floor($img.Height * $ratio))
            $x = $bounds.X + [int](($bounds.Width - $w) / 2)
            $y = $bounds.Y + [int](($bounds.Height - $h) / 2)
            $rect = New-Object System.Drawing.Rectangle($x, $y, $w, $h)
            $e.Graphics.DrawImage($img, $rect)
        }
        finally {
            $img.Dispose()
        }
        $e.HasMorePages = $false
    })

    $printDoc.Print()
    Start-Sleep -Milliseconds 600

    $job = Get-PrintJob -PrinterName $PrinterName -ErrorAction SilentlyContinue |
        Where-Object { $_.DocumentName -eq $DocumentName } |
        Sort-Object -Property SubmittedTime -Descending |
        Select-Object -First 1

    if ($null -eq $job) {
        [pscustomobject]@{ ok = $false; error = 'JOB_ID_NOT_FOUND' } | ConvertTo-Json -Compress
        exit 0
    }

    [pscustomobject]@{ ok = $true; jobId = [int]$job.ID } | ConvertTo-Json -Compress
    exit 0
}
catch {
    [pscustomobject]@{ ok = $false; error = 'SUBMIT_EXCEPTION' } | ConvertTo-Json -Compress
    exit 0
}
