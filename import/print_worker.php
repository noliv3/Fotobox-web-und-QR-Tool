<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';

$command = $argv[1] ?? '';
if ($command !== 'run' && $command !== 'run-loop') {
    fwrite(STDERR, "Usage: php import/print_worker.php [run|run-loop]\n");
    exit(1);
}

$sleepSeconds = 2;
if ($command === 'run-loop') {
    $sleepArg = (int) ($argv[2] ?? 2);
    if ($sleepArg > 0 && $sleepArg <= 30) {
        $sleepSeconds = $sleepArg;
    }
}

do {
    $processed = process_next_job();
    if ($command === 'run-loop') {
        sleep($sleepSeconds);
    }
} while ($command === 'run-loop');

function process_next_job(): bool
{
    $pdo = app_pdo();
    $paths = app_paths();
    $log = $paths['logs'] . '/print_worker.log';

    $jobStmt = $pdo->query("SELECT * FROM print_jobs WHERE status = 'pending' ORDER BY created_ts ASC, id ASC LIMIT 1");
    $job = $jobStmt->fetch();

    if (!$job) {
        write_log($log, 'Kein pending Job vorhanden');
        return false;
    }

    $photoStmt = $pdo->prepare('SELECT * FROM photos WHERE id = :id AND deleted = 0 LIMIT 1');
    $photoStmt->execute(['id' => $job['photo_id']]);
    $photo = $photoStmt->fetch();

    if (!$photo) {
        set_job_status((int) $job['id'], 'error', 'PHOTO_NOT_FOUND');
        write_log($log, 'Job ' . $job['id'] . ' Fehler: PHOTO_NOT_FOUND');
        return true;
    }

    if (!is_photo_printable($photo)) {
        set_job_status((int) $job['id'], 'error', 'OUTSIDE_PRINT_WINDOW');
        write_log($log, 'Job ' . $job['id'] . ' Fehler: OUTSIDE_PRINT_WINDOW');
        return true;
    }

    $imagePath = $paths['originals'] . '/' . $photo['id'] . '.jpg';
    if (!is_file($imagePath)) {
        set_job_status((int) $job['id'], 'error', 'PHOTO_FILE_MISSING');
        write_log($log, 'Job ' . $job['id'] . ' Fehler: PHOTO_FILE_MISSING');
        return true;
    }

    set_job_status((int) $job['id'], 'printing', null);

    if (PHP_OS_FAMILY === 'Windows') {
        set_job_status((int) $job['id'], 'error', 'NOT_IMPLEMENTED_WINDOWS_PRINT');
        write_log($log, 'Job ' . $job['id'] . ' beendet mit error: NOT_IMPLEMENTED_WINDOWS_PRINT');
        return true;
    }

    $printerCommand = command_exists('lp') ? 'lp' : (command_exists('lpr') ? 'lpr' : '');
    if ($printerCommand === '') {
        set_job_status((int) $job['id'], 'error', 'NO_SYSTEM_SPOOLER');
        write_log($log, 'Job ' . $job['id'] . ' beendet mit error: NO_SYSTEM_SPOOLER');
        return true;
    }

    $printerName = getConfiguredPrinterName($pdo);
    $printerOption = '';
    if ($printerName !== '') {
        $printerOption = $printerCommand === 'lp'
            ? (' -d ' . escapeshellarg($printerName))
            : (' -P ' . escapeshellarg($printerName));
    }

    $cmd = $printerCommand . $printerOption . ' ' . escapeshellarg($imagePath) . ' 2>&1';
    exec($cmd, $output, $code);

    if ($code === 0) {
        set_job_status((int) $job['id'], 'done', null);
        write_log($log, 'Job ' . $job['id'] . ' gedruckt');
        return true;
    }

    $error = trim(implode(' | ', $output));
    set_job_status((int) $job['id'], 'error', $error !== '' ? mb_substr($error, 0, 400) : 'PRINT_COMMAND_FAILED');
    write_log($log, 'Job ' . $job['id'] . ' Fehler beim Drucken');
    return true;
}

function set_job_status(int $id, string $status, ?string $error): void
{
    $stmt = app_pdo()->prepare('UPDATE print_jobs SET status = :status, error = :error WHERE id = :id');
    $stmt->execute([
        'id' => $id,
        'status' => $status,
        'error' => $error,
    ]);
}

function command_exists(string $command): bool
{
    $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';
    $result = shell_exec($which . ' ' . escapeshellarg($command) . ' 2>/dev/null');
    return is_string($result) && trim($result) !== '';
}
