<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

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
$file = $type === 'thumb'
    ? $paths['thumbs'] . '/' . $photo['id'] . '.jpg'
    : $paths['originals'] . '/' . $photo['id'] . '.jpg';

if (!is_file($file)) {
    http_response_code(404);
    echo 'Datei fehlt.';
    exit;
}

header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');

if ($type === 'download') {
    header('Content-Disposition: attachment; filename="photo-' . $photo['id'] . '.jpg"');
}

readfile($file);
