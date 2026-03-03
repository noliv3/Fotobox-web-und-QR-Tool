<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();

$pdo = pdo();
$token = $_GET['t'] ?? '';
$type = $_GET['type'] ?? 'thumb';

if (!is_string($token) || !isValidToken($token)) {
    http_response_code(400);
    exit;
}

if (!is_string($type) || !in_array($type, ['thumb', 'original'], true)) {
    http_response_code(400);
    exit;
}

$photo = findPhotoByToken($pdo, $token);
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

header('Content-Type: image/jpeg');
header('Content-Length: ' . (string) filesize($file));
readfile($file);
