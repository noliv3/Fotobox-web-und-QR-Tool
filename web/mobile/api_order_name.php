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
$rateKey = 'rl_order_name_' . getClientIp();
if (!rateLimitCheck($pdo, $rateKey, (int) $cfg['rate_limit_max'], (int) $cfg['rate_limit_window_seconds'])) {
    responseJson(['error' => 'rate_limited'], 429);
}

$csrfHeader = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verifyCsrfToken($csrfHeader)) {
    responseJson(['error' => 'forbidden'], 403);
}

$sessionToken = getOrCreateSessionToken();
$order = getOpenOrder($pdo, $sessionToken, true);
if ($order === null) {
    responseJson(['error' => 'order_create_failed'], 500);
}

$guestName = sanitizeGuestName((string) ($_POST['guest_name'] ?? ''));
$pdo->prepare('UPDATE orders SET guest_name = :guest WHERE id = :id')->execute([
    ':guest' => $guestName,
    ':id' => $order['id'],
]);

responseJson(['ok' => true]);
