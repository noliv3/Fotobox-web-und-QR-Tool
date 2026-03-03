<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();

requireAdminSilently();
$pdo = pdo();

function detectPrinterNames(): array
{
    $names = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $out = shell_exec('powershell -NoProfile -Command "Get-Printer | Select-Object -ExpandProperty Name"');
        if (is_string($out)) {
            foreach (preg_split('/\r?\n/', $out) as $line) {
                $name = trim($line);
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }
    } else {
        $out = shell_exec('lpstat -a 2>/dev/null');
        if (is_string($out)) {
            foreach (preg_split('/\r?\n/', $out) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = preg_split('/\s+/', $line);
                if (is_array($parts) && isset($parts[0]) && $parts[0] !== '') {
                    $names[$parts[0]] = true;
                }
            }
        }
    }

    $result = array_keys($names);
    sort($result);
    return $result;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        responseJson(['ok' => false], 403);
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    setSetting($pdo, 'printer_name', $name);
    adminActionLog('set_printer', ['name' => $name]);
    responseJson(['ok' => true, 'name' => $name]);
}

responseJson([
    'ok' => true,
    'printers' => detectPrinterNames(),
    'current' => getConfiguredPrinterName($pdo),
]);
