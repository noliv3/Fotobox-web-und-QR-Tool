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

function pathPrintfiles(): string
{
    return pathData() . '/printfiles';
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
    ensureDir(pathPrintfiles());
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
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    initDb($pdo);

    return $pdo;
}

function resolvePathInDirectory(string $baseDir, string $filename): ?string
{
    $baseReal = realpath($baseDir);
    if ($baseReal === false) {
        return null;
    }

    $safeName = basename(trim($filename));
    if ($safeName === '' || $safeName === '.' || $safeName === '..') {
        return null;
    }

    $fullPath = $baseReal . DIRECTORY_SEPARATOR . $safeName;
    $realPath = realpath($fullPath);
    if ($realPath === false || !is_file($realPath)) {
        return null;
    }

    $prefix = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($realPath, $prefix) !== 0) {
        return null;
    }

    return $realPath;
}

function initDb(PDO $pdo): void
{
    $schema = [
        'CREATE TABLE IF NOT EXISTS photos (id TEXT PRIMARY KEY, ts INTEGER, filename TEXT, token TEXT UNIQUE, thumb_filename TEXT, deleted INTEGER DEFAULT 0)',
        'CREATE TABLE IF NOT EXISTS print_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, photo_id TEXT, created_ts INTEGER, status TEXT NOT NULL DEFAULT "queued", error TEXT NULL)',
        'CREATE TABLE IF NOT EXISTS kv (key TEXT PRIMARY KEY, value TEXT)',
        'CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY AUTOINCREMENT, created_ts INTEGER, guest_name TEXT, session_token TEXT, status TEXT, note TEXT)',
        'CREATE TABLE IF NOT EXISTS order_items (order_id INTEGER, photo_id TEXT, PRIMARY KEY(order_id, photo_id))',
        'CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)',
        'CREATE INDEX IF NOT EXISTS photos_ts ON photos(ts)',
        'CREATE INDEX IF NOT EXISTS photos_token ON photos(token)',
        'CREATE INDEX IF NOT EXISTS print_jobs_status ON print_jobs(status)',
        'CREATE INDEX IF NOT EXISTS orders_session ON orders(session_token)',
    ];

    foreach ($schema as $sql) {
        $pdo->exec($sql);
    }

    ensurePrintSchema($pdo);

    ensureTableColumns($pdo, 'orders', [
        'created_at' => 'TEXT',
        'name' => 'TEXT',
        'count' => 'INTEGER DEFAULT 0',
        'shipping_enabled' => 'INTEGER DEFAULT 0',
        'price_total' => 'REAL DEFAULT 0',
    ]);
    ensureTableColumns($pdo, 'photos', [
        'fingerprint' => 'TEXT',
    ]);
    migratePhotoFilenameFingerprint($pdo);
}

function ensurePrintSchema(PDO $pdo): void
{
    ensureTableColumns($pdo, 'print_jobs', [
        'status' => "TEXT NOT NULL DEFAULT 'queued'",
        'spool_job_id' => 'INTEGER NULL',
        'document_name' => 'TEXT NULL',
        'attempts' => 'INTEGER NOT NULL DEFAULT 0',
        'last_error' => 'TEXT NULL',
        'last_error_at' => 'INTEGER NULL',
        'next_retry_at' => 'INTEGER NULL',
        'printfile_path' => 'TEXT NULL',
        'updated_at' => 'INTEGER NOT NULL DEFAULT 0',
    ]);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_print_jobs_status_nextretry ON print_jobs(status, next_retry_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_print_jobs_spool ON print_jobs(spool_job_id)');

    $pdo->exec("UPDATE print_jobs SET status = 'queued' WHERE status IS NULL OR status = '' OR status = 'pending'");
    $pdo->exec("UPDATE print_jobs SET last_error = error WHERE last_error IS NULL AND error IS NOT NULL AND trim(error) != ''");
    $pdo->exec("UPDATE print_jobs SET attempts = 0 WHERE attempts IS NULL");
    $pdo->exec("UPDATE print_jobs SET updated_at = COALESCE(updated_at, created_ts, strftime('%s','now')) WHERE updated_at IS NULL OR updated_at = 0");
}

function printBackoffSeconds(int $attempts): int
{
    $step = max(0, min($attempts - 1, 5));
    return min(300, 10 * (2 ** $step));
}

function createPrintfileForJob(string $photoId, int $jobId): ?string
{
    $source = pathOriginals() . '/' . $photoId . '.jpg';
    if (!is_file($source)) {
        return null;
    }

    ensureDir(pathPrintfiles());
    $target = pathPrintfiles() . '/' . $jobId . '.jpg';
    if (!@copy($source, $target)) {
        return null;
    }

    return $target;
}

function ensureTableColumns(PDO $pdo, string $table, array $columns): void
{
    $existing = [];
    $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    foreach ($rows as $row) {
        $existing[(string) $row['name']] = true;
    }

    foreach ($columns as $name => $definition) {
        if (isset($existing[$name])) {
            continue;
        }
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $name . ' ' . $definition);
    }
}

function migratePhotoFilenameFingerprint(PDO $pdo): void
{
    $rows = $pdo->query('SELECT id, filename, thumb_filename, fingerprint, deleted FROM photos WHERE deleted = 0')->fetchAll();
    if ($rows === []) {
        return;
    }

    $update = $pdo->prepare('UPDATE photos SET filename = :filename, thumb_filename = :thumb_filename, fingerprint = :fingerprint WHERE id = :id');
    foreach ($rows as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id === '') {
            continue;
        }

        $filename = (string) ($row['filename'] ?? '');
        $thumbFilename = (string) ($row['thumb_filename'] ?? '');
        $fingerprint = (string) ($row['fingerprint'] ?? '');
        $changed = false;

        $originalFile = pathOriginals() . '/' . $id . '.jpg';
        if ($fingerprint === '' && preg_match('/^[a-f0-9]{40}$/', $filename) && is_file($originalFile)) {
            $fingerprint = $filename;
            $filename = $id . '.jpg';
            $changed = true;
        }

        $thumbFile = pathThumbs() . '/' . $id . '.jpg';
        if ($thumbFilename === '' && is_file($thumbFile)) {
            $thumbFilename = $id . '.jpg';
            $changed = true;
        }

        if (!$changed) {
            continue;
        }

        $update->execute([
            ':id' => $id,
            ':filename' => $filename,
            ':thumb_filename' => $thumbFilename,
            ':fingerprint' => $fingerprint,
        ]);
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

function getSetting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    if (!is_string($value)) {
        return $default;
    }
    return $value;
}

function setSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO settings(key, value) VALUES(:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
    ]);
}

function isPrintConfigured(?array $cfg = null): bool
{
    $cfg = $cfg ?? config();
    $apiKey = trim((string) ($cfg['print_api_key'] ?? ''));
    return $apiKey !== '' && $apiKey !== 'CHANGE_ME_PRINT_API_KEY';
}

function getConfiguredPrinterName(PDO $pdo): string
{
    $cfgPrinter = trim((string) (config()['printer_name'] ?? ''));
    if ($cfgPrinter !== '') {
        return $cfgPrinter;
    }
    return trim(getSetting($pdo, 'printer_name', ''));
}


function initMobileSession(): void
{
    session_name('pb_mobile');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['favs']) || !is_array($_SESSION['favs'])) {
        $_SESSION['favs'] = [];
    }

    $csrfToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($csrfToken) || !preg_match('/^[a-f0-9]{32,128}$/', $csrfToken)) {
        $_SESSION['csrf_token'] = random_token(32);
    }
}

function getCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('csrf_requires_active_session');
    }

    $token = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{32,128}$/', $token)) {
        $token = random_token(32);
        $_SESSION['csrf_token'] = $token;
    }

    return $token;
}

function verifyCsrfToken(?string $provided): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($expected) || $expected === '') {
        return false;
    }

    $provided = is_string($provided) ? trim($provided) : '';
    if ($provided === '') {
        return false;
    }

    return hash_equals($expected, $provided);
}

function isAdminEnabled(?array $cfg = null): bool
{
    $cfg = $cfg ?? config();
    $code = trim((string) ($cfg['admin_code'] ?? ''));
    return $code !== '' && $code !== 'CHANGE_ME_ADMIN_CODE';
}

function adminCodeMatches(?string $providedCode, ?array $cfg = null): bool
{
    $cfg = $cfg ?? config();
    if (!isAdminEnabled($cfg)) {
        return false;
    }

    $provided = trim((string) $providedCode);
    $expected = trim((string) ($cfg['admin_code'] ?? ''));
    if ($provided === '') {
        return false;
    }

    return hash_equals($expected, $provided);
}


function createPrintTicket(string $photoId): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('print_ticket_requires_active_session');
    }

    if (!isset($_SESSION['print_tickets']) || !is_array($_SESSION['print_tickets'])) {
        $_SESSION['print_tickets'] = [];
    }

    $token = generateToken(24);
    $_SESSION['print_tickets'][$token] = [
        'photo_id' => $photoId,
        'expires_ts' => nowTs() + 300,
    ];

    foreach ($_SESSION['print_tickets'] as $key => $ticket) {
        $expires = (int) (($ticket['expires_ts'] ?? 0));
        if ($expires <= nowTs()) {
            unset($_SESSION['print_tickets'][$key]);
        }
    }

    return $token;
}

function consumePrintTicket(string $token, string $photoId): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $tickets = $_SESSION['print_tickets'] ?? [];
    if (!is_array($tickets) || !isset($tickets[$token]) || !is_array($tickets[$token])) {
        return false;
    }

    $ticket = $tickets[$token];
    unset($_SESSION['print_tickets'][$token]);

    $ticketPhotoId = (string) ($ticket['photo_id'] ?? '');
    $expiresTs = (int) ($ticket['expires_ts'] ?? 0);

    if ($ticketPhotoId === '' || $ticketPhotoId !== $photoId) {
        return false;
    }

    return $expiresTs > nowTs();
}

function requireAdminSilently(): string
{
    $cfg = config();

    session_name('pb_admin');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (isset($_SESSION['pb_admin_ok']) && $_SESSION['pb_admin_ok'] === true) {
        return 'session';
    }

    if (!isAdminEnabled($cfg)) {
        header('Location: /mobile/', true, 302);
        exit;
    }

    $providedCode = $_POST['code'] ?? ($_GET['code'] ?? '');
    if (is_string($providedCode) && adminCodeMatches($providedCode, $cfg)) {
        $_SESSION['pb_admin_ok'] = true;
        session_regenerate_id(true);
        return 'code';
    }

    $providedPassword = $_POST['password'] ?? '';
    $hash = trim((string) ($cfg['admin_password_hash'] ?? ''));
    if (is_string($providedPassword) && $providedPassword !== '' && $hash !== '' && $hash !== 'CHANGE_ME' && password_verify($providedPassword, $hash)) {
        $_SESSION['pb_admin_ok'] = true;
        session_regenerate_id(true);
        return 'password';
    }

    header('Location: /mobile/', true, 302);
    exit;
}

function adminActionLog(string $action, array $context = []): void
{
    $line = $action;
    if ($context !== []) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $path = pathLogs() . '/admin.log';
    ensureDir((string) dirname($path));
    logLine($path, $line);
}

// Legacy compatibility wrappers used by import/ and older web endpoints.
function app_config(): array
{
    return config();
}

function app_paths(): array
{
    return [
        'root' => ROOT,
        'data' => pathData(),
        'watch' => (string) config()['watch_path'],
        'originals' => pathOriginals(),
        'thumbs' => pathThumbs(),
        'queue' => pathQueue(),
        'logs' => pathLogs(),
        'printfiles' => pathPrintfiles(),
        'db' => dbPath(),
    ];
}

function app_pdo(): PDO
{
    return pdo();
}

function initialize_database(): void
{
    initDb(pdo());
}

function write_log(string $file, string $line): void
{
    ensureDir((string) dirname($file));
    logLine($file, $line);
}

function random_token(int $bytes = 18): string
{
    return generateToken($bytes);
}

function validate_token(string $token): bool
{
    return isValidToken($token);
}

function find_photo_by_token(string $token): ?array
{
    return findPhotoByToken(pdo(), $token);
}

function is_photo_printable(array $photo): bool
{
    $windowMinutes = (int) (config()['gallery_window_minutes'] ?? 15);
    return nowTs() - (int) ($photo['ts'] ?? 0) <= ($windowMinutes * 60);
}
