<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';

const APP_ROOT = __DIR__ . '/..';

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $configFile = __DIR__ . '/config.php';
    if (!is_file($configFile)) {
        $configFile = __DIR__ . '/config.example.php';
    }

    $config = require $configFile;
    date_default_timezone_set((string) ($config['timezone'] ?? 'Europe/Vienna'));
    return $config;
}

function app_paths(): array
{
    static $paths = null;
    if ($paths !== null) {
        return $paths;
    }

    $cfg = app_config();
    $dataPath = realpath($cfg['data_path']) ?: $cfg['data_path'];

    $paths = [
        'data' => $dataPath,
        'watch' => $cfg['watch_path'],
        'originals' => $dataPath . '/originals',
        'thumbs' => $dataPath . '/thumbs',
        'queue' => $dataPath . '/queue',
        'logs' => $dataPath . '/logs',
        'db' => $dataPath . '/queue/photobox.sqlite',
    ];

    foreach (['data', 'watch', 'originals', 'thumbs', 'queue', 'logs'] as $name) {
        ensure_dir($paths[$name]);
    }

    return $paths;
}

function app_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $paths = app_paths();
    $isNew = !is_file($paths['db']);
    $pdo = new PDO('sqlite:' . $paths['db']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if ($isNew) {
        initialize_database($pdo);
    }

    return $pdo;
}

function initialize_database(?PDO $pdo = null): void
{
    $pdo ??= app_pdo();

    $sql = [
        'CREATE TABLE IF NOT EXISTS photos (
            id TEXT PRIMARY KEY,
            ts INTEGER,
            filename TEXT,
            token TEXT UNIQUE,
            thumb_filename TEXT,
            deleted INTEGER DEFAULT 0
        )',
        'CREATE TABLE IF NOT EXISTS print_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            photo_id TEXT,
            created_ts INTEGER,
            status TEXT,
            error TEXT NULL
        )',
        'CREATE TABLE IF NOT EXISTS kv (
            key TEXT PRIMARY KEY,
            value TEXT
        )',
        'CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_ts INTEGER,
            guest_name TEXT,
            session_token TEXT,
            status TEXT,
            note TEXT
        )',
        'CREATE TABLE IF NOT EXISTS order_items (
            order_id INTEGER,
            photo_id TEXT,
            PRIMARY KEY(order_id, photo_id)
        )',
        'CREATE INDEX IF NOT EXISTS idx_photos_ts ON photos(ts)',
        'CREATE INDEX IF NOT EXISTS idx_print_jobs_status ON print_jobs(status)',
        'CREATE INDEX IF NOT EXISTS idx_orders_session ON orders(session_token)',
    ];

    foreach ($sql as $statement) {
        $pdo->exec($statement);
    }
}

function require_session_token(): string
{
    $cookie = $_COOKIE['pb_session'] ?? '';
    if (is_string($cookie) && validate_token($cookie)) {
        return $cookie;
    }

    $token = random_token(32);
    setcookie('pb_session', $token, [
        'expires' => time() + 86400 * 30,
        'path' => '/',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['pb_session'] = $token;
    return $token;
}

function find_photo_by_token(string $token): ?array
{
    $stmt = app_pdo()->prepare('SELECT * FROM photos WHERE token = :token AND deleted = 0 LIMIT 1');
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function is_photo_printable(array $photo): bool
{
    $windowMinutes = (int) app_config()['gallery_window_minutes'];
    return ((int) $photo['ts']) >= (time() - $windowMinutes * 60);
}

function apply_rate_limit(string $scope): bool
{
    $cfg = app_config();
    $max = (int) $cfg['rate_limit_max'];
    $window = (int) $cfg['rate_limit_window_seconds'];
    $now = time();

    $key = sprintf('rate:%s:%s', $scope, client_ip());
    $stmt = app_pdo()->prepare('SELECT value FROM kv WHERE key = :key');
    $stmt->execute(['key' => $key]);
    $existing = $stmt->fetchColumn();

    $timestamps = [];
    if (is_string($existing) && $existing !== '') {
        $decoded = json_decode($existing, true);
        if (is_array($decoded)) {
            $timestamps = array_filter($decoded, static fn ($ts) => is_int($ts) || ctype_digit((string) $ts));
            $timestamps = array_map('intval', $timestamps);
        }
    }

    $timestamps = array_values(array_filter($timestamps, static fn (int $ts) => $ts >= ($now - $window)));
    if (count($timestamps) >= $max) {
        return false;
    }

    $timestamps[] = $now;
    $upsert = app_pdo()->prepare('INSERT INTO kv(key, value) VALUES(:key,:value) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $upsert->execute([
        'key' => $key,
        'value' => json_encode($timestamps),
    ]);

    return true;
}
