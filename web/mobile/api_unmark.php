<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();
initMobileSession();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responseJson(['error' => 'method_not_allowed'], 405);
}

$pdo = pdo();
$cfg = config();
$rateKey = 'rl_unmark_' . getClientIp();
if (!rateLimitCheck($pdo, $rateKey, (int) $cfg['rate_limit_max'], (int) $cfg['rate_limit_window_seconds'])) {
    responseJson(['error' => 'rate_limited'], 429);
}

$csrfHeader = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verifyCsrfToken($csrfHeader)) {
    responseJson(['error' => 'forbidden'], 403);
}

$token = (string) ($_POST['t'] ?? '');
if (!isValidToken($token)) {
    responseJson(['error' => 'invalid_token'], 400);
}

$photo = findPhotoByToken($pdo, $token);
if ($photo === null) {
    responseJson(['error' => 'photo_not_found'], 404);
}

unset($_SESSION['favs'][(string) $photo['id']]);

responseJson(['itemsCount' => count($_SESSION['favs'])]);
