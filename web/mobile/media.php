<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noIndexHeaders();

$token = $_GET['t'] ?? '';
$type = $_GET['type'] ?? 'photo';

if (!is_string($token) || !validate_token($token)) {
    http_response_code(400);
    echo 'Ungültiger Token.';
    exit;
}

if (!in_array($type, ['thumb', 'photo', 'download'], true)) {
    http_response_code(400);
    echo 'Ungültiger Typ.';
    exit;
}

$photo = find_photo_by_token($token);
if (!$photo) {
    http_response_code(404);
    echo 'Nicht gefunden.';
    exit;
}

$paths = app_paths();
$filename = trim((string) ($photo['filename'] ?? ''));
if ($filename === '') {
    $filename = (string) $photo['id'] . '.jpg';
}
$thumbFilename = trim((string) ($photo['thumb_filename'] ?? ''));
if ($thumbFilename === '') {
    $thumbFilename = (string) $photo['id'] . '.jpg';
}

$file = $type === 'thumb'
    ? resolvePathInDirectory($paths['thumbs'], $thumbFilename)
    : resolvePathInDirectory($paths['originals'], $filename);

if ($file === null || !is_file($file)) {
    http_response_code(404);
    exit;
}

if ($type === 'download') {
    $downloadName = 'photo_' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $photo['id']) . '.jpg';
    sendFileCached($file, 'image/jpeg', $downloadName);
}

sendFileCached($file, 'image/jpeg');
