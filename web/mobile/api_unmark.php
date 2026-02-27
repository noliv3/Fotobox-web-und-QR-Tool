<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();
requirePost();

$pdo = pdo();
$token = $_POST['t'] ?? '';
if (!is_string($token) || !isValidToken($token)) {
    responseJson(['error' => 'invalid_token'], 400);
}

$photo = findPhotoByToken($pdo, $token);
if ($photo === null) {
    responseJson(['error' => 'photo_not_found'], 404);
}

$sessionToken = getOrCreateSessionToken();
$order = getOpenOrder($pdo, $sessionToken, false);
if ($order === null) {
    responseJson(['itemsCount' => 0]);
}

$pdo->prepare('DELETE FROM order_items WHERE order_id = :orderId AND photo_id = :photoId')->execute([
    ':orderId' => $order['id'],
    ':photoId' => $photo['id'],
]);

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :orderId');
$countStmt->execute([':orderId' => $order['id']]);

responseJson(['itemsCount' => (int) $countStmt->fetchColumn()]);
