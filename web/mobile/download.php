<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noIndexHeaders();

$pdo = pdo();
$photoId = trim((string) ($_GET['id'] ?? ''));
$token = trim((string) ($_GET['t'] ?? ''));

$photo = null;
if ($photoId !== '') {
    $stmt = $pdo->prepare('SELECT id, filename FROM photos WHERE id = :id AND deleted = 0 LIMIT 1');
    $stmt->execute([':id' => $photoId]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $photo = $row;
    }
} elseif ($token !== '' && isValidToken($token)) {
    $photo = findPhotoByToken($pdo, $token);
}

if ($photo === null) {
    http_response_code(404);
    exit;
}

$filename = trim((string) ($photo['filename'] ?? ''));
if ($filename === '') {
    $filename = (string) $photo['id'] . '.jpg';
}

$file = resolvePathInDirectory(pathOriginals(), $filename);
if ($file === null) {
    http_response_code(404);
    exit;
}

$downloadName = 'photo_' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $photo['id']) . '.jpg';
sendFileCached($file, 'image/jpeg', $downloadName);
