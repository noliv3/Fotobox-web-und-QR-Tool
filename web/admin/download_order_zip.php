<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();
requireAdminSilently();

$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    echo 'invalid_order_id';
    exit;
}

$pdo = pdo();
$stmt = $pdo->prepare('SELECT id, zip_path FROM orders WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $orderId]);
$order = $stmt->fetch();
if (!is_array($order)) {
    http_response_code(404);
    echo 'not_found';
    exit;
}

$zipPath = trim((string) ($order['zip_path'] ?? ''));
if ($zipPath === '' || !is_file($zipPath)) {
    http_response_code(404);
    echo 'zip_not_found';
    exit;
}

$realZip = realpath($zipPath);
$ordersRoot = realpath(pathOrders());
if ($realZip === false || $ordersRoot === false) {
    http_response_code(404);
    echo 'zip_not_found';
    exit;
}

$prefix = rtrim($ordersRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (strpos($realZip, $prefix) !== 0) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

header('Content-Type: application/zip');
header('Content-Length: ' . (string) filesize($realZip));
header('Content-Disposition: attachment; filename="order_' . (int) $order['id'] . '.zip"');
readfile($realZip);
exit;
