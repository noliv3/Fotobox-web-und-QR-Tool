<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';

$command = $argv[1] ?? '';
if ($command !== 'run') {
    fwrite(STDERR, "Usage: php import/print_worker.php run\n");
    exit(1);
}

$pdo = app_pdo();
$paths = app_paths();
$log = $paths['logs'] . '/print_worker.log';

$jobStmt = $pdo->query("SELECT * FROM print_jobs WHERE status = 'pending' ORDER BY created_ts ASC, id ASC LIMIT 1");
$job = $jobStmt->fetch();

if (!$job) {
    write_log($log, 'Kein pending Job vorhanden');
    exit(0);
}

$photoStmt = $pdo->prepare('SELECT * FROM photos WHERE id = :id AND deleted = 0 LIMIT 1');
$photoStmt->execute(['id' => $job['photo_id']]);
$photo = $photoStmt->fetch();

if (!$photo) {
    set_job_status((int) $job['id'], 'error', 'PHOTO_NOT_FOUND');
    write_log($log, 'Job ' . $job['id'] . ' Fehler: PHOTO_NOT_FOUND');
    exit(0);
}

if (!is_photo_printable($photo)) {
    set_job_status((int) $job['id'], 'error', 'OUTSIDE_PRINT_WINDOW');
    write_log($log, 'Job ' . $job['id'] . ' Fehler: OUTSIDE_PRINT_WINDOW');
    exit(0);
}

$imagePath = $paths['originals'] . '/' . $photo['id'] . '.jpg';
if (!is_file($imagePath)) {
    set_job_status((int) $job['id'], 'error', 'PHOTO_FILE_MISSING');
    write_log($log, 'Job ' . $job['id'] . ' Fehler: PHOTO_FILE_MISSING');
    exit(0);
}

set_job_status((int) $job['id'], 'printing', null);

if (PHP_OS_FAMILY === 'Windows') {
    set_job_status((int) $job['id'], 'pending', 'NOT_IMPLEMENTED_WINDOWS_PRINT');
    write_log($log, 'Job ' . $job['id'] . ' bleibt pending: NOT_IMPLEMENTED_WINDOWS_PRINT');
    exit(0);
}

$printerCommand = command_exists('lp') ? 'lp' : (command_exists('lpr') ? 'lpr' : '');
if ($printerCommand === '') {
    set_job_status((int) $job['id'], 'pending', 'NO_SYSTEM_SPOOLER');
    write_log($log, 'Job ' . $job['id'] . ' bleibt pending: NO_SYSTEM_SPOOLER');
    exit(0);
}

$cmd = $printerCommand . ' ' . escapeshellarg($imagePath) . ' 2>&1';
exec($cmd, $output, $code);

if ($code === 0) {
    set_job_status((int) $job['id'], 'done', null);
    write_log($log, 'Job ' . $job['id'] . ' gedruckt');
    exit(0);
}

$error = trim(implode(' | ', $output));
set_job_status((int) $job['id'], 'error', $error !== '' ? mb_substr($error, 0, 400) : 'PRINT_COMMAND_FAILED');
write_log($log, 'Job ' . $job['id'] . ' Fehler beim Drucken');

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
