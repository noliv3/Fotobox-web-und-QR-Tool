<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();
initMobileSession();

if (!isset($_SESSION['upload_print_items']) || !is_array($_SESSION['upload_print_items'])) {
    http_response_code(404);
    exit('not_found');
}

$id = trim((string) ($_GET['id'] ?? ''));
if ($id === '' || !preg_match('/^[a-f0-9]{24}$/', $id)) {
    http_response_code(400);
    exit('invalid_id');
}

$item = $_SESSION['upload_print_items'][$id] ?? null;
if (!is_array($item)) {
    http_response_code(404);
    exit('not_found');
}

$sessionId = preg_replace('/[^a-zA-Z0-9]/', '', session_id());
if (!is_string($sessionId) || $sessionId === '') {
    http_response_code(404);
    exit('not_found');
}
$dir = pathData() . '/uploads/session_' . $sessionId;

$filename = basename((string) ($item['filename'] ?? ''));
if ($filename === '') {
    http_response_code(404);
    exit('not_found');
}

$path = resolvePathInDirectory($dir, $filename);
if ($path === null) {
    http_response_code(404);
    exit('not_found');
}

$mime = strtolower(trim((string) ($item['mime'] ?? '')));
if ($mime !== 'image/jpeg' && $mime !== 'image/png') {
    $mime = 'image/jpeg';
}

session_write_close();
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
$size = filesize($path);
if (is_int($size) && $size >= 0) {
    header('Content-Length: ' . (string) $size);
}
readfile($path);
exit;
