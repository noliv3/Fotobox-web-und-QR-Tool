<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();

$pdo = pdo();
$token = $_GET['t'] ?? '';
if (!is_string($token) || !isValidToken($token)) {
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

$file = pathOriginals() . '/' . $filename;
if (!is_file($file)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Disposition: attachment; filename="photo_' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $photo['id']) . '.jpg"');
header('Content-Length: ' . (string) filesize($file));
readfile($file);
