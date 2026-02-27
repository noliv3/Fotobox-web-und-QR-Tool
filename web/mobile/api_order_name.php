<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

require_post();

$guestName = sanitize_guest_name((string) ($_POST['guest_name'] ?? ''));
$sessionToken = require_session_token();
$pdo = app_pdo();

$orderStmt = $pdo->prepare('SELECT id FROM orders WHERE session_token = :session_token ORDER BY id DESC LIMIT 1');
$orderStmt->execute(['session_token' => $sessionToken]);
$orderId = (int) ($orderStmt->fetchColumn() ?: 0);

if ($orderId <= 0) {
    $create = $pdo->prepare('INSERT INTO orders(created_ts, guest_name, session_token, status, note) VALUES(:created_ts, :guest_name, :session_token, :status, :note)');
    $create->execute([
        'created_ts' => time(),
        'guest_name' => $guestName,
        'session_token' => $sessionToken,
        'status' => 'open',
        'note' => '',
    ]);
    respond_json(['ok' => true]);
}

$update = $pdo->prepare('UPDATE orders SET guest_name = :guest_name WHERE id = :id');
$update->execute(['guest_name' => $guestName, 'id' => $orderId]);
respond_json(['ok' => true]);
