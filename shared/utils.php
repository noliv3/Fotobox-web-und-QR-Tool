<?php

declare(strict_types=1);

function ensure_dir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Directory konnte nicht erstellt werden: ' . $path);
    }
}

function random_token(int $length = 32): string
{
    $bytes = random_bytes((int) ceil($length / 2));
    return substr(bin2hex($bytes), 0, $length);
}

function validate_token(string $token): bool
{
    return (bool) preg_match('/^[a-f0-9]{24,128}$/', $token);
}

function validate_hhmm(string $value): bool
{
    return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value);
}

function sanitize_guest_name(string $name): string
{
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '';
    }

    $safe = preg_replace('/[^\p{L}\p{N}\s\-_.]/u', '', $trimmed);
    $safe = $safe ?? '';
    return mb_substr($safe, 0, 80);
}

function write_log(string $filePath, string $message): void
{
    $line = sprintf("[%s] %s\n", date('c'), $message);
    file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);
}

function respond_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond_json(['error' => 'method_not_allowed'], 405);
    }
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr($ip, 0, 64);
}
