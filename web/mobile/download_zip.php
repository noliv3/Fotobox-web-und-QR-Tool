<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();

session_name('pb_mobile');
session_start();
if (!isset($_SESSION['favs']) || !is_array($_SESSION['favs'])) {
    $_SESSION['favs'] = [];
}

$favIds = array_values(array_keys($_SESSION['favs']));
if ($favIds === []) {
    http_response_code(400);
    echo '<p>Fehler</p><p><a href="/mobile/">Zurueck</a></p>';
    exit;
}

$pdo = pdo();
$placeholders = implode(',', array_fill(0, count($favIds), '?'));
$stmt = $pdo->prepare('SELECT id FROM photos WHERE deleted = 0 AND id IN (' . $placeholders . ')');
$stmt->execute($favIds);
$rows = $stmt->fetchAll();
if ($rows === []) {
    http_response_code(400);
    echo '<p>Fehler</p><p><a href="/mobile/">Zurueck</a></p>';
    exit;
}

$tmpDir = pathData() . '/tmp';
ensureDir($tmpDir);
$sessionToken = getOrCreateSessionToken();
$zipPath = $tmpDir . '/' . preg_replace('/[^a-zA-Z0-9]/', '', $sessionToken) . '_' . nowTs() . '.zip';

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo '<p>Fehler</p><p><a href="/mobile/">Zurueck</a></p>';
    exit;
}

foreach ($rows as $row) {
    $photoId = (string) $row['id'];
    $originalPath = pathOriginals() . '/' . $photoId . '.jpg';
    if (is_file($originalPath)) {
        $zip->addFile($originalPath, $photoId . '.jpg');
    }
}
$zip->close();

if (!is_file($zipPath)) {
    http_response_code(500);
    echo '<p>Fehler</p><p><a href="/mobile/">Zurueck</a></p>';
    exit;
}

$filename = 'photobox_' . date('Ymd_Hi') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($zipPath));
readfile($zipPath);
@unlink($zipPath);
exit;
