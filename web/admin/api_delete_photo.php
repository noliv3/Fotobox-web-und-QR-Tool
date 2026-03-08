<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();

requireAdminSilently();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responseJson(['ok' => false], 405);
}

if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    responseJson(['ok' => false], 403);
}

$photoId = trim((string) ($_POST['id'] ?? ''));
if ($photoId === '' || !isValidPhotoId($photoId)) {
    responseJson(['ok' => false], 400);
}

$pdo = pdo();
$stmt = $pdo->prepare('SELECT id FROM photos WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $photoId]);
if (!$stmt->fetchColumn()) {
    responseJson(['ok' => false], 404);
}

$original = pathOriginals() . '/' . $photoId . '.jpg';
$thumb = pathThumbs() . '/' . $photoId . '.jpg';
if (is_file($original)) {
    @unlink($original);
}
if (is_file($thumb)) {
    @unlink($thumb);
}

$deleteStmt = $pdo->prepare('UPDATE photos SET deleted = 1 WHERE id = :id');
$deleteStmt->execute([':id' => $photoId]);
adminActionLog('delete_photo', ['id' => $photoId]);

responseJson(['ok' => true]);
