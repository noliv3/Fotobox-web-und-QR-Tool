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
    return mb_substr(trim($value), 0, 80);
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
    setcookie('pb_sess', $token, [
        'expires' => nowTs() + 86400 * 30,
        'path' => '/',
        'secure' => false,
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
