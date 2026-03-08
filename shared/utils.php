<?php

declare(strict_types=1);

function nowTs(): int
{
    return time();
}

function sanitizeGuestName(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/[^\p{L}\p{N}\s\-_.]/u', '', $value) ?? '';
    return textSubstr(trim($value), 0, 80);
}

function textSubstr(string $value, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        return $length === null
            ? (string) mb_substr($value, $start, null, 'UTF-8')
            : (string) mb_substr($value, $start, $length, 'UTF-8');
    }

    if (function_exists('iconv_substr')) {
        $result = $length === null
            ? iconv_substr($value, $start, iconv_strlen($value, 'UTF-8'), 'UTF-8')
            : iconv_substr($value, $start, $length, 'UTF-8');
        return $result === false ? '' : (string) $result;
    }

    return $length === null ? substr($value, $start) : substr($value, $start, $length);
}

function validateTimeHHMM(string $value): bool
{
    return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value);
}

function parseTimeToTsToday(string $hhmm, string $timezone): int
{
    if (!validateTimeHHMM($hhmm)) {
        throw new InvalidArgumentException('invalid_hhmm');
    }

    $tz = new DateTimeZone($timezone);
    $today = new DateTimeImmutable('now', $tz);
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    $target = $today->setTime($h, $m, 0);

    return $target->getTimestamp();
}

function generateToken(int $bytes = 18): string
{
    return bin2hex(random_bytes($bytes));
}

function getClientIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!is_string($ip) || $ip === '') {
        return '0.0.0.0';
    }

    return substr($ip, 0, 64);
}

function requirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        responseJson(['error' => 'method_not_allowed'], 405);
    }
}

function rateLimitCheck(PDO $pdo, string $key, int $max, int $windowSeconds): bool
{
    $now = nowTs();
    $stmt = $pdo->prepare('SELECT value FROM kv WHERE key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetchColumn();

    $state = ['count' => 0, 'expires' => 0];
    if (is_string($row) && $row !== '') {
        $decoded = json_decode($row, true);
        if (is_array($decoded)) {
            $state['count'] = (int) ($decoded['count'] ?? 0);
            $state['expires'] = (int) ($decoded['expires'] ?? 0);
        }
    }

    if ($state['expires'] <= $now) {
        $state = ['count' => 0, 'expires' => $now + $windowSeconds];
    }

    if ($state['count'] >= $max) {
        return false;
    }

    $state['count']++;
    $upsert = $pdo->prepare('INSERT INTO kv(key, value) VALUES(:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $upsert->execute([
        ':key' => $key,
        ':value' => json_encode($state, JSON_UNESCAPED_SLASHES),
    ]);

    return true;
}

function getOrCreateSessionToken(): string
{
    $cookie = $_COOKIE['pb_sess'] ?? '';
    if (is_string($cookie) && preg_match('/^[a-f0-9]{24,128}$/', $cookie)) {
        return $cookie;
    }

    $token = generateToken(18);
    $secureCookie = false;
    $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
    if (($https !== '' && $https !== 'off' && $https !== '0') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        $secureCookie = true;
    }
    setcookie('pb_sess', $token, [
        'expires' => nowTs() + 86400 * 30,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $_COOKIE['pb_sess'] = $token;
    return $token;
}

function logLine(string $file, string $line): void
{
    $prefix = '[' . date('c') . '] ';
    file_put_contents($file, $prefix . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}
