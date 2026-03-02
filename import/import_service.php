<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';

$command = $argv[1] ?? '';

switch ($command) {
    case 'init-db':
        initialize_database();
        echo "DB initialisiert: " . app_paths()['db'] . PHP_EOL;
        exit(0);

    case 'ingest':
        run_ingest();
        exit(0);

    case 'ingest-file':
        $file = $argv[2] ?? '';
        if ($file === '') {
            fwrite(STDERR, "Usage: php import/import_service.php ingest-file <path>\n");
            exit(1);
        }
        run_ingest_file($file);
        exit(0);

    case 'cleanup':
        run_cleanup();
        exit(0);

    default:
        fwrite(STDERR, "Usage: php import/import_service.php [init-db|ingest|ingest-file|cleanup]\n");
        exit(1);
}

function run_ingest(): void
{
    $cfg = app_config();
    $paths = app_paths();
    $sourceRoot = (string) ($cfg['watch_path'] ?? $paths['watch']);

    if (($cfg['import_mode'] ?? 'watch_folder') === 'sd_card') {
        $sdCardPath = trim((string) ($cfg['sd_card_path'] ?? ''));
        if ($sdCardPath !== '') {
            $sourceRoot = $sdCardPath;
        }
    }

    if (!is_dir($sourceRoot)) {
        write_log($paths['logs'] . '/import.log', 'Import-Quelle nicht lesbar: ' . $sourceRoot);
        return;
    }

    $files = find_jpeg_files_recursive($sourceRoot);
    sort($files);
    foreach ($files as $sourceFile) {
        process_source_file($sourceFile);
    }
}

function find_jpeg_files_recursive(string $root): array
{
    $result = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $ext = strtolower((string) $fileInfo->getExtension());
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $result[] = $fileInfo->getPathname();
        }
    }

    return $result;
}

function run_ingest_file(string $path): void
{
    process_source_file($path);
}

function process_source_file(string $sourceFile): void
{
    $paths = app_paths();
    $pdo = app_pdo();
    $log = $paths['logs'] . '/import.log';

    if (!is_file($sourceFile)) {
        write_log($log, 'Datei nicht gefunden: ' . $sourceFile);
        return;
    }

    $extension = strtolower((string) pathinfo($sourceFile, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg'], true)) {
        write_log($log, 'Übersprungen (kein JPEG): ' . basename($sourceFile));
        return;
    }

    $fingerprint = sha1_file($sourceFile) ?: '';
    if ($fingerprint === '') {
        write_log($log, 'Hash fehlgeschlagen: ' . basename($sourceFile));
        return;
    }

    $hasFingerprint = photos_has_column($pdo, 'fingerprint');
    $exists = $hasFingerprint
        ? $pdo->prepare('SELECT id FROM photos WHERE fingerprint = :fingerprint AND deleted = 0 LIMIT 1')
        : $pdo->prepare('SELECT id FROM photos WHERE filename = :filename LIMIT 1');
    $exists->execute($hasFingerprint ? ['fingerprint' => $fingerprint] : ['filename' => $fingerprint]);
    if ($exists->fetchColumn()) {
        write_log($log, 'Bereits importiert: ' . basename($sourceFile));
        return;
    }

    $id = bin2hex(random_bytes(16));
    $token = random_token(32);
    $destOriginal = $paths['originals'] . '/' . $id . '.jpg';
    $destThumb = $paths['thumbs'] . '/' . $id . '.jpg';

    if (!copy($sourceFile, $destOriginal)) {
        write_log($log, 'Kopieren fehlgeschlagen: ' . basename($sourceFile));
        return;
    }

    if (!create_thumbnail($destOriginal, $destThumb, 600)) {
        @unlink($destOriginal);
        write_log($log, 'Thumbnail fehlgeschlagen: ' . basename($sourceFile));
        return;
    }

    $ts = filemtime($sourceFile) ?: time();
    $insert = $hasFingerprint
        ? $pdo->prepare('INSERT INTO photos(id, ts, filename, token, thumb_filename, deleted, fingerprint) VALUES(:id,:ts,:filename,:token,:thumb,0,:fingerprint)')
        : $pdo->prepare('INSERT INTO photos(id, ts, filename, token, thumb_filename, deleted) VALUES(:id,:ts,:filename,:token,:thumb,0)');
    $filename = $id . '.jpg';
    $thumbFilename = $id . '.jpg';
    $insertPayload = [
        'id' => $id,
        'ts' => $ts,
        'filename' => $filename,
        'token' => $token,
        'thumb' => $thumbFilename,
    ];
    if ($hasFingerprint) {
        $insertPayload['fingerprint'] = $fingerprint;
    }
    $insert->execute($insertPayload);

    write_log($log, sprintf('Importiert: %s -> %s', basename($sourceFile), $id));
}

function photos_has_column(PDO $pdo, string $column): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $rows = $pdo->query('PRAGMA table_info(photos)')->fetchAll();
    foreach ($rows as $row) {
        if (isset($row['name']) && (string) $row['name'] === $column) {
            $cache[$column] = true;
            return true;
        }
    }

    $cache[$column] = false;
    return false;
}

function run_cleanup(): void
{
    $cfg = app_config();
    $paths = app_paths();
    $pdo = app_pdo();
    $log = $paths['logs'] . '/cleanup.log';

    $threshold = time() - ((int) $cfg['retention_days'] * 86400);
    $stmt = $pdo->prepare('SELECT * FROM photos WHERE deleted = 0 AND ts < :threshold');
    $stmt->execute(['threshold' => $threshold]);

    $update = $pdo->prepare('UPDATE photos SET deleted = 1 WHERE id = :id');
    foreach ($stmt->fetchAll() as $photo) {
        $id = $photo['id'];
        $original = $paths['originals'] . '/' . $id . '.jpg';
        $thumb = $paths['thumbs'] . '/' . $id . '.jpg';

        if (is_file($original)) {
            @unlink($original);
        }
        if (is_file($thumb)) {
            @unlink($thumb);
        }

        $update->execute(['id' => $id]);
        write_log($log, 'Gelöscht (Retention): ' . $id);
    }
}

function create_thumbnail(string $source, string $destination, int $targetWidth): bool
{
    // Fallback für Umgebungen ohne GD: Thumbnail bleibt funktional als Kopie.
    if (!function_exists('imagecreatefromjpeg')) {
        return copy($source, $destination);
    }

    $img = @imagecreatefromjpeg($source);
    if (!$img) {
        return false;
    }

    $width = imagesx($img);
    $height = imagesy($img);
    if ($width <= 0 || $height <= 0) {
        imagedestroy($img);
        return false;
    }

    $targetHeight = (int) max(1, floor($height * ($targetWidth / $width)));
    $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
    imagecopyresampled($thumb, $img, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

    $ok = imagejpeg($thumb, $destination, 82);
    imagedestroy($thumb);
    imagedestroy($img);
    return $ok;
}
