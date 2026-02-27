<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

require_post();

$token = $_POST['token'] ?? '';
if (!is_string($token) || !validate_token($token)) {
    respond_json(['error' => 'invalid_token'], 400);
}

$photo = find_photo_by_token($token);
if (!$photo) {
    respond_json(['error' => 'photo_not_found'], 404);
}

$sessionToken = require_session_token();
$guestName = sanitize_guest_name((string) ($_POST['guest_name'] ?? ''));
$pdo = app_pdo();

$orderStmt = $pdo->prepare('SELECT * FROM orders WHERE session_token = :session_token ORDER BY id DESC LIMIT 1');
$orderStmt->execute(['session_token' => $sessionToken]);
$order = $orderStmt->fetch();

if (!$order) {
    $create = $pdo->prepare('INSERT INTO orders(created_ts, guest_name, session_token, status, note) VALUES(:created_ts, :guest_name, :session_token, :status, :note)');
    $create->execute([
        'created_ts' => time(),
        'guest_name' => $guestName,
        'session_token' => $sessionToken,
        'status' => 'open',
        'note' => '',
    ]);
    $orderId = (int) $pdo->lastInsertId();
} else {
    $orderId = (int) $order['id'];
    if ($guestName !== '') {
        $upd = $pdo->prepare('UPDATE orders SET guest_name = :guest_name WHERE id = :id');
        $upd->execute(['guest_name' => $guestName, 'id' => $orderId]);
    }
}

$item = $pdo->prepare('INSERT OR IGNORE INTO order_items(order_id, photo_id) VALUES(:order_id, :photo_id)');
$item->execute(['order_id' => $orderId, 'photo_id' => $photo['id']]);

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :order_id');
$countStmt->execute(['order_id' => $orderId]);

respond_json([
    'orderId' => $orderId,
    'itemsCount' => (int) $countStmt->fetchColumn(),
]);
