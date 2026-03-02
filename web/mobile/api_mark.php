<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();

session_name('pb_mobile');
session_start();

if (!isset($_SESSION['favs']) || !is_array($_SESSION['favs'])) {
    $_SESSION['favs'] = [];
}

$pdo = pdo();
$cfg = config();
$rateKey = 'rl_mark_' . getClientIp();
if (!rateLimitCheck($pdo, $rateKey, (int) $cfg['rate_limit_max'], (int) $cfg['rate_limit_window_seconds'])) {
    responseJson(['ok' => false, 'error' => 'rate_limited'], 429);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['list'])) {
    responseJson([
        'ok' => true,
        'ids' => array_keys($_SESSION['favs']),
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responseJson(['ok' => false], 405);
}

$action = (string) ($_POST['action'] ?? '');
$photoId = trim((string) ($_POST['id'] ?? ''));
if ($photoId === '') {
    responseJson(['ok' => false], 400);
}

$stmt = $pdo->prepare('SELECT id FROM photos WHERE id = :id AND deleted = 0 LIMIT 1');
$stmt->execute([':id' => $photoId]);
if (!$stmt->fetchColumn()) {
    responseJson(['ok' => false], 404);
}

switch ($action) {
    case 'add':
        $_SESSION['favs'][$photoId] = true;
        responseJson(['ok' => true, 'state' => 'added']);
    case 'remove':
        unset($_SESSION['favs'][$photoId]);
        responseJson(['ok' => true, 'state' => 'removed']);
    case 'toggle':
        $exists = isset($_SESSION['favs'][$photoId]);
        if ($exists) {
            unset($_SESSION['favs'][$photoId]);
            responseJson(['ok' => true, 'state' => 'removed']);
        }
        $_SESSION['favs'][$photoId] = true;
        responseJson(['ok' => true, 'state' => 'added']);
    default:
        responseJson(['ok' => false], 400);
}
