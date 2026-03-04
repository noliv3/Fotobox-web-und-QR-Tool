<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noIndexHeaders();

$pdo = pdo();
$type = $_GET['type'] ?? 'thumb';
$photoId = trim((string) ($_GET['id'] ?? ''));
$token = trim((string) ($_GET['t'] ?? ''));

if (!is_string($type) || !in_array($type, ['thumb', 'original'], true)) {
    http_response_code(400);
    exit;
}

$photo = null;
if ($photoId !== '') {
    $stmt = $pdo->prepare('SELECT id, token, filename, thumb_filename FROM photos WHERE id = :id AND deleted = 0 LIMIT 1');
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
$thumbFilename = trim((string) ($photo['thumb_filename'] ?? ''));
if ($thumbFilename === '') {
    $thumbFilename = (string) $photo['id'] . '.jpg';
}

$file = $type === 'thumb'
    ? resolvePathInDirectory(pathThumbs(), $thumbFilename)
    : resolvePathInDirectory(pathOriginals(), $filename);

if ($file === null) {
    http_response_code(404);
    exit;
}

sendFileCached($file, 'image/jpeg');
