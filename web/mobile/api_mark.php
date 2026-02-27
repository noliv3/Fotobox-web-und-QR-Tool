<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();
requirePost();

$pdo = pdo();
$token = $_POST['t'] ?? '';
$guestName = sanitizeGuestName((string) ($_POST['guest_name'] ?? ''));

if (!is_string($token) || !isValidToken($token)) {
    responseJson(['error' => 'invalid_token'], 400);
}

$photo = findPhotoByToken($pdo, $token);
if ($photo === null) {
    responseJson(['error' => 'photo_not_found'], 404);
}

$sessionToken = getOrCreateSessionToken();
$order = getOpenOrder($pdo, $sessionToken, true);
if ($order === null) {
    responseJson(['error' => 'order_create_failed'], 500);
}

if ($guestName !== '') {
    $pdo->prepare('UPDATE orders SET guest_name = :guest WHERE id = :id')->execute([
        ':guest' => $guestName,
        ':id' => $order['id'],
    ]);
}

$pdo->prepare('INSERT INTO order_items(order_id, photo_id) VALUES(:orderId, :photoId) ON CONFLICT(order_id, photo_id) DO NOTHING')->execute([
    ':orderId' => $order['id'],
    ':photoId' => $photo['id'],
]);

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :orderId');
$countStmt->execute([':orderId' => $order['id']]);

responseJson([
    'orderId' => (int) $order['id'],
    'itemsCount' => (int) $countStmt->fetchColumn(),
]);
