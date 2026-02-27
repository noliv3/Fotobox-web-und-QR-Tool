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
$pdo = app_pdo();

$orderStmt = $pdo->prepare('SELECT id FROM orders WHERE session_token = :session_token ORDER BY id DESC LIMIT 1');
$orderStmt->execute(['session_token' => $sessionToken]);
$orderId = (int) ($orderStmt->fetchColumn() ?: 0);

if ($orderId > 0) {
    $del = $pdo->prepare('DELETE FROM order_items WHERE order_id = :order_id AND photo_id = :photo_id');
    $del->execute(['order_id' => $orderId, 'photo_id' => $photo['id']]);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :order_id');
    $countStmt->execute(['order_id' => $orderId]);
    respond_json(['itemsCount' => (int) $countStmt->fetchColumn()]);
}

respond_json(['itemsCount' => 0]);
