<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();
requirePost();

initMobileSession();

$pdo = pdo();
$cfg = config();

$token = $_POST['t'] ?? '';
if (!is_string($token) || !isValidToken($token)) {
    responseJson(['error' => 'invalid_token'], 400);
}

$photo = findPhotoByToken($pdo, $token);
if ($photo === null) {
    responseJson(['error' => 'photo_not_found'], 404);
}

if (nowTs() - (int) $photo['ts'] > ((int) $cfg['gallery_window_minutes'] * 60)) {
    responseJson(['error' => 'outside_print_window'], 403);
}

$configuredApiKey = trim((string) ($cfg['print_api_key'] ?? ''));
$apiKeyConfigured = $configuredApiKey !== '' && $configuredApiKey !== 'CHANGE_ME_PRINT_API_KEY';
$printConfigured = $apiKeyConfigured || getConfiguredPrinterName($pdo) !== '';
if (!$printConfigured) {
    responseJson(['error' => 'print_not_configured'], 503);
}

$csrfToken = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
$printTicket = (string) ($_POST['print_ticket'] ?? '');
if (!verifyCsrfToken($csrfToken) || !consumePrintTicket($printTicket, (string) $photo['id'])) {
    responseJson(['error' => 'forbidden'], 403);
}

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($apiKeyConfigured && $apiKey !== '' && (!is_string($apiKey) || !hash_equals($configuredApiKey, $apiKey))) {
    responseJson(['error' => 'forbidden'], 403);
}

$rateKey = 'rl_print_' . getClientIp();
if (!rateLimitCheck($pdo, $rateKey, (int) $cfg['rate_limit_max'], (int) $cfg['rate_limit_window_seconds'])) {
    responseJson(['error' => 'rate_limited'], 429);
}

$openCountStmt = $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status IN ('queued','sending','spooled','needs_attention','paused')");
$openCount = (int) $openCountStmt->fetchColumn();
if ($openCount >= 50) {
    responseJson(['error' => 'queue_full'], 503);
}

$createdTs = nowTs();
$stmt = $pdo->prepare('INSERT INTO print_jobs(photo_id, created_ts, status, error, last_error, attempts, updated_at) VALUES(:photoId, :createdTs, :status, :error, :lastError, :attempts, :updatedAt)');
$stmt->execute([
    ':photoId' => $photo['id'],
    ':createdTs' => $createdTs,
    ':status' => 'queued',
    ':error' => null,
    ':lastError' => null,
    ':attempts' => 0,
    ':updatedAt' => $createdTs,
]);

$jobId = (int) $pdo->lastInsertId();
$printfile = createPrintfileForJob((string) $photo['id'], $jobId);
if ($printfile === null) {
    $failed = $pdo->prepare('UPDATE print_jobs SET status = :status, last_error = :error, error = :error, last_error_at = :errorAt, updated_at = :updatedAt WHERE id = :id');
    $failed->execute([
        ':status' => 'failed_hard',
        ':error' => 'RENDER_FAILED',
        ':errorAt' => nowTs(),
        ':updatedAt' => nowTs(),
        ':id' => $jobId,
    ]);

    responseJson(['ok' => false, 'job_id' => $jobId, 'status' => 'failed_hard', 'error' => 'RENDER_FAILED'], 500);
}

$update = $pdo->prepare('UPDATE print_jobs SET printfile_path = :printfilePath, updated_at = :updatedAt WHERE id = :id');
$update->execute([
    ':printfilePath' => $printfile,
    ':updatedAt' => nowTs(),
    ':id' => $jobId,
]);

responseJson(['ok' => true, 'job_id' => $jobId, 'status' => 'queued']);

