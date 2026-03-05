<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();

function renderZipInfoPage(int $statusCode, string $title, string $message): never
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= esc($title) ?></title>
  <style>
    body{font-family:Segoe UI,Tahoma,sans-serif;background:#f3f5f9;color:#1d2733;margin:0;padding:1rem}
    .card{max-width:640px;margin:2rem auto;background:#fff;border:1px solid #d8deea;border-radius:12px;padding:1rem}
    a{color:#0e4ca8}
  </style>
</head>
<body>
  <div class="card">
    <h1><?= esc($title) ?></h1>
    <p><?= esc($message) ?></p>
    <p><a href="/mobile/?view=favs">Zur Merkliste</a></p>
  </div>
</body>
</html>
    <?php
    exit;
}

initMobileSession();
$favIds = array_values(array_keys($_SESSION['favs']));
if ($favIds === []) {
    renderZipInfoPage(200, 'Merkliste ist leer', 'Bitte markiere zuerst Fotos, bevor du ein ZIP herunterlädst.');
}

if (!class_exists('ZipArchive')) {
    renderZipInfoPage(503, 'ZIP nicht verfügbar', 'Auf diesem System fehlt die PHP-ZIP-Erweiterung (ZipArchive).');
}

$maxItems = 200;
if (count($favIds) > $maxItems) {
    renderZipInfoPage(503, 'Zu viele Fotos', 'Bitte reduziere deine Merkliste auf maximal 200 Fotos für einen ZIP-Download.');
}

$tmpDir = pathData() . '/tmp';
if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
    renderZipInfoPage(503, 'ZIP nicht verfügbar', 'Temporäres Verzeichnis konnte nicht erstellt werden.');
}
if (!is_writable($tmpDir)) {
    renderZipInfoPage(503, 'ZIP nicht verfügbar', 'Temporäres Verzeichnis ist nicht beschreibbar.');
}

$pdo = pdo();
$placeholders = implode(',', array_fill(0, count($favIds), '?'));
$stmt = $pdo->prepare('SELECT id, ts FROM photos WHERE deleted = 0 AND id IN (' . $placeholders . ')');
$stmt->execute($favIds);
$rows = $stmt->fetchAll();
if ($rows === []) {
    renderZipInfoPage(200, 'Merkliste ist leer', 'Es wurden keine gültigen Fotos für den ZIP-Download gefunden.');
}

$sessionToken = preg_replace('/[^a-zA-Z0-9]/', '', getOrCreateSessionToken());
$zipPath = $tmpDir . '/photobox_' . $sessionToken . '_' . nowTs() . '.zip';

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    renderZipInfoPage(503, 'ZIP nicht verfügbar', 'ZIP-Datei konnte nicht erzeugt werden.');
}

$addedCount = 0;
foreach ($rows as $row) {
    $photoId = basename((string) ($row['id'] ?? ''));
    if ($photoId === '' || $photoId === '.' || $photoId === '..') {
        continue;
    }

    $originalPath = resolvePathInDirectory(pathOriginals(), $photoId . '.jpg');
    if ($originalPath === null || !is_file($originalPath)) {
        continue;
    }

    $ts = (int) ($row['ts'] ?? 0);
    if ($ts <= 0) {
        $ts = time();
    }
    $zipEntryName = date('Ymd_His', $ts) . '_' . $photoId . '.jpg';

    if ($zip->addFile($originalPath, $zipEntryName)) {
        $addedCount++;
    }
}
$zip->close();

if ($addedCount === 0) {
    if (is_file($zipPath)) {
        @unlink($zipPath);
    }
    renderZipInfoPage(200, 'Merkliste ist leer', 'Keine Originaldateien verfügbar. Bitte versuche es später erneut.');
}

if (!is_file($zipPath)) {
    renderZipInfoPage(503, 'ZIP nicht verfügbar', 'ZIP-Datei wurde nicht erstellt.');
}

$zipSize = filesize($zipPath);
if (!is_int($zipSize) || $zipSize <= 0) {
    @unlink($zipPath);
    renderZipInfoPage(503, 'ZIP nicht verfügbar', 'ZIP-Datei ist leer oder beschädigt.');
}

if (ob_get_level()) {
    while (ob_get_level()) {
        ob_end_clean();
    }
}

$filename = 'photobox_' . date('Ymd_Hi') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) $zipSize);
header('Cache-Control: no-store');
readfile($zipPath);
@unlink($zipPath);
exit;
