<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();
requireAdminSilently();

function outputFallback(): void
{
    http_response_code(200);
    header('Content-Type: image/svg+xml; charset=UTF-8');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80">'
        . '<rect width="80" height="80" fill="#edf1f7"/>'
        . '<text x="40" y="46" font-size="11" text-anchor="middle" fill="#6e7c91">NO CAM</text>'
        . '</svg>';
    exit;
}

function fetchPreview(): ?array
{
    $url = 'http://127.0.0.1:5513/preview.jpg';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT_MS => 700,
            CURLOPT_TIMEOUT_MS => 1200,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            curl_close($ch);
            return null;
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $type = (string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '');
        curl_close($ch);

        $body = (string) substr($raw, $headerSize);
        if ($status !== 200 || $body === '') {
            return null;
        }

        return ['type' => $type, 'body' => $body];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 1.2,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') {
        return null;
    }

    $type = '';
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                $type = trim((string) substr($line, 13));
                break;
            }
        }
    }

    return ['type' => $type, 'body' => $body];
}

$preview = fetchPreview();
if (!is_array($preview)) {
    outputFallback();
}

$type = strtolower(trim((string) ($preview['type'] ?? '')));
$body = (string) ($preview['body'] ?? '');
if ($body === '' || !str_starts_with($type, 'image/')) {
    outputFallback();
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($body));
echo $body;
exit;

