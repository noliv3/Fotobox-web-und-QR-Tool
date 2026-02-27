<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';

const ROOT = __DIR__ . '/..';

function config(): array
{
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }

    $cfg = require __DIR__ . '/config.example.php';
    $localFile = __DIR__ . '/config.php';
    if (is_file($localFile)) {
        $local = require $localFile;
        if (is_array($local)) {
            $cfg = array_merge($cfg, $local);
        }
    }

    date_default_timezone_set((string) ($cfg['timezone'] ?? 'Europe/Vienna'));

    return $cfg;
}

function ensureDir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('dir_create_failed: ' . $path);
    }
}

function pathData(): string
{
    return (string) config()['data_path'];
}

function pathOriginals(): string
{
    return pathData() . '/originals';
}

function pathThumbs(): string
{
    return pathData() . '/thumbs';
}

function pathQueue(): string
{
    return pathData() . '/queue';
}

function pathLogs(): string
{
    return pathData() . '/logs';
}

function dbPath(): string
{
    return pathQueue() . '/photobox.sqlite';
}

function ensureAppDirs(): void
{
    ensureDir(pathData());
    ensureDir((string) config()['watch_path']);
    ensureDir(pathOriginals());
    ensureDir(pathThumbs());
    ensureDir(pathQueue());
    ensureDir(pathLogs());
}

function pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException(
            'pdo_sqlite fehlt. Bitte in php.ini aktivieren: extension=pdo_sqlite und optional extension=sqlite3. '
            . 'Zusätzlich php --ini prüfen und defekte zusätzliche INI-Dateien reparieren.'
        );
    }

    ensureAppDirs();
    $pdo = new PDO('sqlite:' . dbPath());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    initDb($pdo);

    return $pdo;
}

function initDb(PDO $pdo): void
{
    $schema = [
        'CREATE TABLE IF NOT EXISTS photos (id TEXT PRIMARY KEY, ts INTEGER, filename TEXT, token TEXT UNIQUE, thumb_filename TEXT, deleted INTEGER DEFAULT 0)',
        'CREATE TABLE IF NOT EXISTS print_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, photo_id TEXT, created_ts INTEGER, status TEXT, error TEXT NULL)',
        'CREATE TABLE IF NOT EXISTS kv (key TEXT PRIMARY KEY, value TEXT)',
        'CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY AUTOINCREMENT, created_ts INTEGER, guest_name TEXT, session_token TEXT, status TEXT, note TEXT)',
        'CREATE TABLE IF NOT EXISTS order_items (order_id INTEGER, photo_id TEXT, PRIMARY KEY(order_id, photo_id))',
        'CREATE INDEX IF NOT EXISTS photos_ts ON photos(ts)',
        'CREATE INDEX IF NOT EXISTS photos_token ON photos(token)',
        'CREATE INDEX IF NOT EXISTS print_jobs_status ON print_jobs(status)',
        'CREATE INDEX IF NOT EXISTS orders_session ON orders(session_token)',
    ];

    foreach ($schema as $sql) {
        $pdo->exec($sql);
    }
}

function responseJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function noCacheHeaders(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function noIndexHeaders(): void
{
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}

function isValidToken(string $token): bool
{
    return (bool) preg_match('/^[a-f0-9]{24,128}$/', $token);
}

function findPhotoByToken(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM photos WHERE token = :token AND deleted = 0 LIMIT 1');
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function getOpenOrder(PDO $pdo, string $sessionToken, bool $create = true): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE session_token = :session AND status = :status ORDER BY id DESC LIMIT 1');
    $stmt->execute([':session' => $sessionToken, ':status' => 'open']);
    $row = $stmt->fetch();
    if (is_array($row)) {
        return $row;
    }

    if (!$create) {
        return null;
    }

    $insert = $pdo->prepare('INSERT INTO orders(created_ts, guest_name, session_token, status, note) VALUES(:ts, :guest, :session, :status, :note)');
    $insert->execute([
        ':ts' => nowTs(),
        ':guest' => '',
        ':session' => $sessionToken,
        ':status' => 'open',
        ':note' => '',
    ]);

    $id = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $created = $stmt->fetch();

    return is_array($created) ? $created : null;
}
