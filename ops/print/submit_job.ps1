param(
    [Parameter(Mandatory = $true)][string]$PrinterName,
    [Parameter(Mandatory = $true)][string]$File,
    [Parameter(Mandatory = $true)][string]$DocumentName
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
        Write-Output (@{ ok = $false; error = 'SPOOLER_STOPPED' } | ConvertTo-Json -Compress)
        exit 0
    }

    $printer = Get-Printer -Name $PrinterName -ErrorAction Stop
    if ($null -eq $printer) {
        Write-Output (@{ ok = $false; error = 'PRINTER_NOT_FOUND' } | ConvertTo-Json -Compress)
        exit 0
    }

    if ([string]$printer.Name -match 'Microsoft Print to PDF|OneNote') {
        Write-Output (@{ ok = $false; error = 'VIRTUAL_PRINTER_UNSUPPORTED' } | ConvertTo-Json -Compress)
        exit 0
    }

    if (-not (Test-Path -LiteralPath $File)) {
        Write-Output (@{ ok = $false; error = 'PRINTFILE_MISSING' } | ConvertTo-Json -Compress)
        exit 0
    }

    Add-Type -Language CSharp -ReferencedAssemblies @('System.Drawing', 'System.Windows.Forms') -TypeDefinition @"
using System;
using System.Drawing;
using System.Drawing.Printing;

public static class PhotoboxPrintHelper {
    public static void PrintFill(string printerName, string filePath, string documentName) {
        using (var img = Image.FromFile(filePath))
        using (var doc = new PrintDocument()) {
            doc.PrinterSettings.PrinterName = printerName;
            doc.DocumentName = documentName;
            doc.PrintPage += (sender, e) => {
                var dest = e.MarginBounds;
                float imgAspect = (float)img.Width / (float)img.Height;
                float destAspect = (float)dest.Width / (float)dest.Height;

                Rectangle srcRect;
                if (imgAspect > destAspect) {
                    int srcW = (int)Math.Floor(img.Height * destAspect);
                    int srcX = (img.Width - srcW) / 2;
                    srcRect = new Rectangle(srcX, 0, srcW, img.Height);
                } else {
                    int srcH = (int)Math.Floor(img.Width / destAspect);
                    int srcY = (img.Height - srcH) / 2;
                    srcRect = new Rectangle(0, srcY, img.Width, srcH);
                }

                e.Graphics.DrawImage(img, dest, srcRect, GraphicsUnit.Pixel);
                e.HasMorePages = false;
            };

            doc.Print();
        }
    }
}
"@

    $uniqueDocumentName = 'photobox_job_' + $DocumentName + '_' + [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
    [PhotoboxPrintHelper]::PrintFill($PrinterName, $File, $uniqueDocumentName)

    $job = $null
    for ($i = 0; $i -lt 10; $i++) {
        Start-Sleep -Milliseconds 200
        $job = Get-PrintJob -PrinterName $PrinterName -ErrorAction SilentlyContinue |
            Where-Object { $_.DocumentName -eq $uniqueDocumentName } |
            Sort-Object -Property SubmittedTime -Descending |
            Select-Object -First 1
        if ($null -ne $job) {
            break
        }
    }

    if ($null -eq $job) {
        Write-Output (@{ ok = $false; error = 'JOB_ID_NOT_FOUND' } | ConvertTo-Json -Compress)
        exit 0
    }

    Write-Output (@{ ok = $true; jobId = [int]$job.ID; documentName = $uniqueDocumentName } | ConvertTo-Json -Compress)
    exit 0
}
catch {
    $code = 'PS_EXCEPTION'
    if ($_.FullyQualifiedErrorId -like '*GetPrinter*' -or $_.CategoryInfo.Reason -eq 'CimJobException') {
        $code = 'PRINTER_NOT_FOUND'
    } elseif ($_.FullyQualifiedErrorId -like '*ServiceCommandException*') {
        $code = 'SPOOLER_STOPPED'
    } elseif ($_.Exception -and $_.Exception.GetType().Name -like '*Win32Exception*') {
        $code = 'JOB_ID_NOT_FOUND'
    }

    Write-Output (@{ ok = $false; error = $code } | ConvertTo-Json -Compress)
    exit 0
}
