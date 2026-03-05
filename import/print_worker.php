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
    run_worker_once();
    if ($command === 'run-loop') {
        sleep($sleepSeconds);
    }
} while ($command === 'run-loop');

function run_worker_once(): void
{
    $pdo = app_pdo();
    $log = app_paths()['logs'] . '/print_worker.log';
    write_log($log, 'worker_run_start');

    if (PHP_OS_FAMILY !== 'Windows') {
        write_log($log, 'worker_run_stop platform_not_supported');
        return;
    }

    $printerName = getConfiguredPrinterName($pdo);
    if ($printerName === '') {
        write_log($log, 'worker_run_stop printer_not_configured');
        return;
    }

    if (!isSpoolerRunning()) {
        write_log($log, 'worker_run_stop spooler_not_running');
        return;
    }

    $printerStatus = getPrinterStatus($printerName);
    if (!(bool) ($printerStatus['ok'] ?? false)) {
        write_log($log, 'worker_run_stop printer_not_found');
        return;
    }

    recoverTransientAttentionJobs($pdo, $log);
    recoverStuckSendingJobs($pdo, $log);
    pollSpooledJob($pdo, $printerName, $log);

    $online = (bool) ($printerStatus['online'] ?? false);
    $paused = (bool) ($printerStatus['paused'] ?? false);
    $errorState = strtolower(trim((string) ($printerStatus['errorState'] ?? '')));
    $queueCount = (int) ($printerStatus['queueCount'] ?? 0);
    $hasPrinterError = !in_array($errorState, ['', 'normal', 'idle', 'ready'], true);

    if (!$online || $paused || $hasPrinterError) {
        write_log($log, 'worker_run_stop printer_needs_attention state=' . $errorState);
        return;
    }

    if ($queueCount > 0) {
        write_log($log, 'worker_run_stop backpressure queue=' . $queueCount);
        return;
    }

    if (hasActivePipelineJob($pdo)) {
        write_log($log, 'worker_run_stop backpressure active_job');
        return;
    }

    submitNextQueuedJob($pdo, $printerName, $log);
    write_log($log, 'worker_run_stop');
}

function hasActivePipelineJob(PDO $pdo): bool
{
    $stmt = $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status IN ('sending','spooled')");
    $count = (int) $stmt->fetchColumn();
    return $count > 0;
}

function recoverTransientAttentionJobs(PDO $pdo, string $log): void
{
    $now = nowTs();
    $stmt = $pdo->prepare(
        "UPDATE print_jobs
         SET status = 'queued', updated_at = :now
         WHERE status = 'needs_attention'
           AND last_error IN ('JOB_ID_NOT_FOUND')
           AND (next_retry_at IS NULL OR next_retry_at <= :now)"
    );
    $stmt->execute([':now' => $now]);
    $count = $stmt->rowCount();
    if ($count > 0) {
        write_log($log, 'requeued_transient_jobs count=' . $count);
    }
}

function recoverStuckSendingJobs(PDO $pdo, string $log): void
{
    $now = nowTs();
    $stmt = $pdo->prepare(
        "UPDATE print_jobs
         SET status = 'queued', updated_at = :now
         WHERE status = 'sending'
           AND spool_job_id IS NULL
           AND updated_at > 0
           AND updated_at <= :staleBefore"
    );
    $stmt->execute([
        ':now' => $now,
        ':staleBefore' => $now - 30,
    ]);
    $count = $stmt->rowCount();
    if ($count > 0) {
        write_log($log, 'requeued_stuck_sending count=' . $count);
    }
}

function pollSpooledJob(PDO $pdo, string $printerName, string $log): void
{
    $stmt = $pdo->query("SELECT * FROM print_jobs WHERE status = 'spooled' AND spool_job_id IS NOT NULL ORDER BY created_ts ASC, id ASC LIMIT 1");
    $job = $stmt->fetch();
    if (!is_array($job)) {
        return;
    }

    $spoolId = (int) $job['spool_job_id'];
    $status = getSpoolJobStatus($printerName, $spoolId);
    if (trim((string) ($status['error'] ?? '')) !== '') {
        return;
    }

    if (!(bool) ($status['exists'] ?? false)) {
        updateJobState($pdo, $job, [
            'status' => 'done',
            'spool_job_id' => null,
            'last_error' => null,
            'last_error_at' => null,
            'next_retry_at' => null,
        ], $log);
        return;
    }

    $state = strtolower(trim((string) ($status['state'] ?? '')));
    $flags = $status['flags'] ?? [];
    if (!is_array($flags)) {
        $flags = [];
    }
    $flagsText = strtolower(implode(' ', array_map('strval', $flags)));

    if ($state === 'completed' || $state === 'printed') {
        updateJobState($pdo, $job, [
            'status' => 'done',
            'last_error' => null,
            'next_retry_at' => null,
        ], $log);
        return;
    }

    $error = '';
    if (str_contains($flagsText, 'paperout') || str_contains($flagsText, 'paper out')) {
        $error = 'PAPER_OUT';
    } elseif (str_contains($flagsText, 'offline')) {
        $error = 'OFFLINE';
    } elseif (str_contains($flagsText, 'paused')) {
        $error = 'PAUSED';
    } elseif (str_contains($flagsText, 'error')) {
        $error = 'PRINTER_ERROR';
    }

    if ($error !== '') {
        updateJobState($pdo, $job, [
            'status' => 'needs_attention',
            'last_error' => $error,
            'last_error_at' => nowTs(),
            'next_retry_at' => nowTs() + 60,
        ], $log);
        return;
    }

    if (in_array($state, ['printing', 'spooling', 'retained'], true)) {
        updateJobState($pdo, $job, [
            'status' => 'spooled',
        ], $log);
    }
}

function submitNextQueuedJob(PDO $pdo, string $printerName, string $log): void
{
    $now = nowTs();
    $stmt = $pdo->prepare("SELECT * FROM print_jobs WHERE status = 'queued' AND (next_retry_at IS NULL OR next_retry_at <= :now) ORDER BY created_ts ASC, id ASC LIMIT 1");
    $stmt->execute([':now' => $now]);
    $job = $stmt->fetch();
    if (!is_array($job)) {
        return;
    }

    $printfilePath = trim((string) ($job['printfile_path'] ?? ''));
    if ($printfilePath === '' || !is_file($printfilePath)) {
        updateJobState($pdo, $job, [
            'status' => 'failed_hard',
            'last_error' => 'PRINTFILE_MISSING',
            'last_error_at' => $now,
            'next_retry_at' => null,
        ], $log);
        return;
    }

    updateJobState($pdo, $job, [
        'status' => 'sending',
    ], $log);

    $documentName = (string) ((int) $job['id']);
    $submit = submitSpoolJob($printerName, $printfilePath, $documentName);

    if ((bool) ($submit['ok'] ?? false) && (int) ($submit['jobId'] ?? 0) > 0) {
        updateJobState($pdo, $job, [
            'status' => 'spooled',
            'spool_job_id' => (int) $submit['jobId'],
            'document_name' => (string) ($submit['documentName'] ?? ('photobox_job_' . (int) $job['id'])),
            'last_error' => null,
            'last_error_at' => null,
            'next_retry_at' => null,
        ], $log);
        return;
    }

    $errorTs = nowTs();
    $errorCode = trim((string) ($submit['error'] ?? 'SUBMIT_FAILED'));
    $attempts = ((int) $job['attempts']) + 1;
    $next = $errorTs + printBackoffSeconds($attempts);
    $targetStatus = in_array($errorCode, ['PAPER_OUT', 'OFFLINE', 'PAUSED', 'PRINTER_ERROR', 'SPOOLER_STOPPED', 'PRINTER_NOT_FOUND', 'VIRTUAL_PRINTER_UNSUPPORTED'], true)
        ? 'needs_attention'
        : 'queued';

    updateJobState($pdo, $job, [
        'status' => $targetStatus,
        'attempts' => $attempts,
        'last_error' => $errorCode,
        'last_error_at' => $errorTs,
        'next_retry_at' => $next,
        'spool_job_id' => null,
    ], $log);
}

function updateJobState(PDO $pdo, array $job, array $updates, string $log): void
{
    $current = [
        'id' => (int) $job['id'],
        'status' => (string) ($job['status'] ?? ''),
        'attempts' => (int) ($job['attempts'] ?? 0),
        'last_error' => $job['last_error'] !== null ? (string) $job['last_error'] : null,
        'spool_job_id' => $job['spool_job_id'] !== null ? (int) $job['spool_job_id'] : null,
        'document_name' => $job['document_name'] !== null ? (string) $job['document_name'] : null,
        'last_error_at' => $job['last_error_at'] !== null ? (int) $job['last_error_at'] : null,
        'next_retry_at' => $job['next_retry_at'] !== null ? (int) $job['next_retry_at'] : null,
    ];

    $new = array_merge($current, $updates);
    $new['updated_at'] = nowTs();

    $stmt = $pdo->prepare(
        'UPDATE print_jobs SET status=:status, attempts=:attempts, last_error=:last_error, last_error_at=:last_error_at, '
        . 'next_retry_at=:next_retry_at, spool_job_id=:spool_job_id, document_name=:document_name, updated_at=:updated_at '
        . 'WHERE id=:id'
    );
    $stmt->execute([
        ':id' => $new['id'],
        ':status' => $new['status'],
        ':attempts' => $new['attempts'],
        ':last_error' => $new['last_error'],
        ':last_error_at' => $new['last_error_at'],
        ':next_retry_at' => $new['next_retry_at'],
        ':spool_job_id' => $new['spool_job_id'],
        ':document_name' => $new['document_name'],
        ':updated_at' => $new['updated_at'],
    ]);

    if ($current['status'] !== $new['status']) {
        write_log($log, 'job ' . $new['id'] . ' status ' . $current['status'] . '->' . $new['status']);
    }
    if ($new['last_error'] !== null && $new['last_error'] !== '' && $current['last_error'] !== $new['last_error']) {
        write_log($log, 'job ' . $new['id'] . ' error ' . $new['last_error']);
    }
}

function runPsJson(array $args): array
{
    static $stderrFingerprintByScript = [];

    $base = ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File'];
    $command = array_merge($base, $args);
    $parts = array_map('escapeshellarg', $command);
    $cmd = implode(' ', $parts);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['ok' => false, 'error' => 'POWERSHELL_EXEC_FAILED'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);

    $scriptKey = (string) ($args[0] ?? 'unknown_script');
    $stderrTrimmed = trim((string) $stderr);
    if ($stderrTrimmed !== '') {
        $fingerprint = sha1($stderrTrimmed);
        if (($stderrFingerprintByScript[$scriptKey] ?? '') !== $fingerprint) {
            $stderrFingerprintByScript[$scriptKey] = $fingerprint;
            write_log(app_paths()['logs'] . '/print_worker.log', 'powershell stderr ' . basename($scriptKey) . ' ' . $stderrTrimmed);
        }
    }

    if ($code !== 0) {
        return ['ok' => false, 'error' => 'POWERSHELL_EXEC_FAILED'];
    }

    $raw = trim((string) $stdout);
    if ($raw === '') {
        return ['ok' => false, 'error' => 'POWERSHELL_EMPTY_RESPONSE'];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'PS_JSON_INVALID'];
    }

    return $decoded;
}

function isSpoolerRunning(): bool
{
    $status = runPsJson([ROOT . '/ops/print/printer_status.ps1', '-PrinterName', '__spooler_probe__']);
    return trim((string) ($status['error'] ?? '')) !== 'SPOOLER_STOPPED';
}

function getPrinterStatus(string $printerName): array
{
    return runPsJson([ROOT . '/ops/print/printer_status.ps1', '-PrinterName', $printerName]);
}

function getSpoolJobStatus(string $printerName, int $jobId): array
{
    return runPsJson([ROOT . '/ops/print/job_status.ps1', '-PrinterName', $printerName, '-JobId', (string) $jobId]);
}

function submitSpoolJob(string $printerName, string $file, string $documentName): array
{
    return runPsJson([
        ROOT . '/ops/print/submit_job.ps1',
        '-PrinterName',
        $printerName,
        '-File',
        $file,
        '-DocumentName',
        $documentName,
    ]);
}
