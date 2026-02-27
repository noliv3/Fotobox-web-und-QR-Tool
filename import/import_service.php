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
    $paths = app_paths();
    $files = glob($paths['watch'] . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE);
    if ($files === false) {
        write_log($paths['logs'] . '/import.log', 'watch_path nicht lesbar');
        return;
    }

    sort($files);
    foreach ($files as $sourceFile) {
        process_source_file($sourceFile);
    }
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

    $exists = $pdo->prepare('SELECT id FROM photos WHERE filename = :filename LIMIT 1');
    $exists->execute(['filename' => $fingerprint]);
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
    $insert = $pdo->prepare('INSERT INTO photos(id, ts, filename, token, thumb_filename, deleted) VALUES(:id,:ts,:filename,:token,:thumb,0)');
    $insert->execute([
        'id' => $id,
        'ts' => $ts,
        'filename' => $fingerprint,
        'token' => $token,
        'thumb' => $id . '.jpg',
    ]);

    write_log($log, sprintf('Importiert: %s -> %s', basename($sourceFile), $id));
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
