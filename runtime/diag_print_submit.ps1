param(
  [string]$PrinterName,
  [string]$File
)
$ErrorActionPreference='Stop'
try {
  $printer = Get-Printer -Name $PrinterName -ErrorAction Stop
  Write-Output ('printer=' + $printer.Name)
  Add-Type -Language CSharp -ReferencedAssemblies @('System.Drawing', 'System.Drawing.Common', 'System.Windows.Forms') -TypeDefinition @"
using System;
using System.Drawing;
using System.Drawing.Printing;
public static class PhotoboxPrintDiag {
  public static void PrintFill(string printerName, string filePath) {
    using (var img = Image.FromFile(filePath))
    using (var doc = new PrintDocument()) {
      doc.PrinterSettings.PrinterName = printerName;
      doc.DocumentName = "diag_" + DateTimeOffset.UtcNow.ToUnixTimeSeconds();
      doc.PrintPage += (sender, e) => {
        var dest = e.MarginBounds;
        e.Graphics.DrawImage(img, dest);
        e.HasMorePages = false;
      };
      doc.Print();
    }
  }
}
"@
  [PhotoboxPrintDiag]::PrintFill($PrinterName, $File)
  Write-Output 'print_ok'
} catch {
  Write-Output ('type=' + $_.Exception.GetType().FullName)
  Write-Output ('msg=' + $_.Exception.Message)
  if ($_.Exception.InnerException) { Write-Output ('inner=' + $_.Exception.InnerException.Message) }
}
