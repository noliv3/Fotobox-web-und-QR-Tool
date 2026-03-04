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
        $out = shell_exec('powershell -NoProfile -NonInteractive -Command "Get-Printer | Select-Object -ExpandProperty Name"');
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

function runCp1500Script(string $mode, string $ipAddress = ''): array
{
    $payload = [
        'ok' => false,
        'mode' => $mode,
        'error' => 'NOT_WINDOWS',
        'detectedNames' => [],
        'spoolerRunning' => null,
        'installedName' => '',
        'autoInstalled' => false,
    ];

    if (PHP_OS_FAMILY !== 'Windows') {
        return $payload;
    }

    $script = ROOT . '/ops/print/discover_cp1500.ps1';
    if (!is_file($script)) {
        $payload['error'] = 'SCRIPT_MISSING';
        return $payload;
    }

    $parts = [
        'powershell',
        '-NoProfile',
        '-NonInteractive',
        '-ExecutionPolicy',
        'Bypass',
        '-File',
        $script,
        '-Mode',
        $mode,
    ];

    if ($ipAddress !== '') {
        $parts[] = '-IpAddress';
        $parts[] = $ipAddress;
    }

    $cmd = implode(' ', array_map('escapeshellarg', $parts));
    $out = shell_exec($cmd);
    if (!is_string($out) || trim($out) === '') {
        $payload['error'] = 'SCRIPT_EMPTY_RESPONSE';
        return $payload;
    }

    $decoded = json_decode(trim($out), true);
    if (!is_array($decoded)) {
        $payload['error'] = 'SCRIPT_INVALID_JSON';
        return $payload;
    }

    return $decoded;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        responseJson(['ok' => false], 403);
    }

    $action = trim((string) ($_POST['action'] ?? 'save'));
    if ($action === 'auto_connect_cp1500') {
        $ip = trim((string) ($_POST['ip'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            responseJson(['ok' => false, 'error' => 'invalid_ip'], 400);
        }

        $result = runCp1500Script('install', $ip);
        $installedName = trim((string) ($result['installedName'] ?? ''));
        if ((bool) ($result['ok'] ?? false) && $installedName !== '') {
            setSetting($pdo, 'printer_name', $installedName);
            adminActionLog('auto_connect_cp1500', ['ip' => $ip, 'name' => $installedName]);
        }

        responseJson([
            'ok' => (bool) ($result['ok'] ?? false),
            'result' => $result,
            'current' => getConfiguredPrinterName($pdo),
            'printers' => detectPrinterNames(),
        ]);
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
    'cp1500' => runCp1500Script('detect'),
]);

