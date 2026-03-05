<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();
requirePost();

session_name('pb_gallery');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$csrfToken = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
if ($csrfToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $csrfToken)) {
    responseJson(['error' => 'forbidden'], 403);
}

$photoId = trim((string) ($_POST['id'] ?? ''));
if ($photoId === '') {
    responseJson(['error' => 'invalid_photo_id'], 400);
}

$pdo = pdo();
$photoStmt = $pdo->prepare('SELECT id FROM photos WHERE id = :id AND deleted = 0 LIMIT 1');
$photoStmt->execute([':id' => $photoId]);
if (!$photoStmt->fetch()) {
    responseJson(['error' => 'photo_not_found'], 404);
}

$key = 'heart_total_' . $photoId;
$upsert = $pdo->prepare("INSERT INTO kv(key, value) VALUES(:key, '1') ON CONFLICT(key) DO UPDATE SET value = CAST(value AS INTEGER) + 1");
$upsert->execute([':key' => $key]);

$countStmt = $pdo->prepare('SELECT value FROM kv WHERE key = :key LIMIT 1');
$countStmt->execute([':key' => $key]);
$total = (int) ($countStmt->fetchColumn() ?: 0);

responseJson(['ok' => true, 'photo_id' => $photoId, 'total' => $total]);
