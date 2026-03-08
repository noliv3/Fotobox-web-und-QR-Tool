<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();
requirePost();

initMobileSession();

$pdo = pdo();
$cfg = config();

$configuredApiKey = trim((string) ($cfg['print_api_key'] ?? ''));
$apiKeyConfigured = $configuredApiKey !== '' && $configuredApiKey !== 'CHANGE_ME_PRINT_API_KEY';
$printConfigured = $apiKeyConfigured || getConfiguredPrinterName($pdo) !== '';
if (!$printConfigured) {
    responseJson(['error' => 'print_not_configured'], 503);
}

$csrfToken = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!verifyCsrfToken($csrfToken)) {
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

$favIds = array_keys($_SESSION['favs'] ?? []);
if ($favIds === []) {
    responseJson(['error' => 'no_favs'], 400);
}

$placeholders = implode(',', array_fill(0, count($favIds), '?'));
$stmt = $pdo->prepare('SELECT id, token, ts, created_at FROM photos WHERE deleted = 0 AND id IN (' . $placeholders . ') ORDER BY created_at DESC, ts DESC');
$stmt->execute($favIds);
$rows = $stmt->fetchAll();

$printable = [];
foreach ($rows as $row) {
    if (is_array($row) && is_photo_printable($row)) {
        $printable[] = $row;
    }
}

if (count($printable) < 2) {
    responseJson(['error' => 'need_two_new_favs'], 400);
}

$selected = array_slice($printable, 0, 2);

$openCountStmt = $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status IN ('queued','sending','spooled','needs_attention','paused')");
$openCount = (int) $openCountStmt->fetchColumn();
if (($openCount + count($selected)) > 50) {
    responseJson(['error' => 'queue_full'], 503);
}

$jobIds = [];
$createdPrintfiles = [];
try {
    $pdo->beginTransaction();
    foreach ($selected as $photo) {
        $createdTs = nowTs();
        $insert = $pdo->prepare('INSERT INTO print_jobs(photo_id, created_ts, status, error, last_error, attempts, updated_at) VALUES(:photoId, :createdTs, :status, :error, :lastError, :attempts, :updatedAt)');
        $insert->execute([
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
            throw new RuntimeException('render_failed');
        }

        $update = $pdo->prepare('UPDATE print_jobs SET printfile_path = :printfilePath, updated_at = :updatedAt WHERE id = :id');
        $update->execute([
            ':printfilePath' => $printfile,
            ':updatedAt' => nowTs(),
            ':id' => $jobId,
        ]);

        $jobIds[] = $jobId;
        $createdPrintfiles[] = $printfile;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($createdPrintfiles as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    write_log(pathLogs() . '/mobile.log', 'api_print_favs_exception ' . $e->getMessage());
    responseJson(['ok' => false, 'error' => 'render_failed', 'job_ids' => []], 500);
}

responseJson([
    'ok' => true,
    'status' => 'queued',
    'count' => count($jobIds),
    'job_ids' => $jobIds,
]);
