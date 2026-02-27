<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();
requirePost();

$pdo = pdo();
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
