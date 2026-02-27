<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();
requirePost();

$pdo = pdo();
$cfg = config();
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? ($_POST['print_api_key'] ?? '');
if (!is_string($apiKey) || !hash_equals((string) $cfg['print_api_key'], $apiKey)) {
    responseJson(['error' => 'forbidden'], 403);
}

$rateKey = 'rl_print_' . getClientIp();
if (!rateLimitCheck($pdo, $rateKey, (int) $cfg['rate_limit_max'], (int) $cfg['rate_limit_window_seconds'])) {
    responseJson(['error' => 'rate_limited'], 429);
}

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

$stmt = $pdo->prepare('INSERT INTO print_jobs(photo_id, created_ts, status, error) VALUES(:photoId, :createdTs, :status, :error)');
$stmt->execute([
    ':photoId' => $photo['id'],
    ':createdTs' => nowTs(),
    ':status' => 'pending',
    ':error' => null,
]);

responseJson(['jobId' => (int) $pdo->lastInsertId()]);
