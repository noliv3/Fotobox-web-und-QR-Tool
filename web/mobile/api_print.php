<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

require_post();

if (!apply_rate_limit('print')) {
    respond_json(['error' => 'rate_limited'], 429);
}

$cfg = app_config();
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? ($_POST['api_key'] ?? '');
if (!is_string($apiKey) || !hash_equals((string) $cfg['print_api_key'], $apiKey)) {
    respond_json(['error' => 'forbidden'], 403);
}

$token = $_POST['token'] ?? '';
if (!is_string($token) || !validate_token($token)) {
    respond_json(['error' => 'invalid_token'], 400);
}

$photo = find_photo_by_token($token);
if (!$photo) {
    respond_json(['error' => 'photo_not_found'], 404);
}

if (!is_photo_printable($photo)) {
    respond_json(['error' => 'outside_print_window'], 403);
}

$stmt = app_pdo()->prepare('INSERT INTO print_jobs(photo_id, created_ts, status, error) VALUES(:photo_id, :created_ts, :status, NULL)');
$stmt->execute([
    'photo_id' => $photo['id'],
    'created_ts' => time(),
    'status' => 'pending',
]);

respond_json(['jobId' => (int) app_pdo()->lastInsertId()]);
