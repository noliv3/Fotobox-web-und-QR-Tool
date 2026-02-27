<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

$sessionToken = require_session_token();
$pdo = app_pdo();

$orderStmt = $pdo->prepare('SELECT * FROM orders WHERE session_token = :session_token ORDER BY id DESC LIMIT 1');
$orderStmt->execute(['session_token' => $sessionToken]);
$order = $orderStmt->fetch();
$items = [];

if ($order) {
    $itemsStmt = $pdo->prepare('SELECT p.token, p.ts, p.id FROM order_items oi JOIN photos p ON p.id = oi.photo_id WHERE oi.order_id = :order_id AND p.deleted = 0 ORDER BY p.ts DESC');
    $itemsStmt->execute(['order_id' => $order['id']]);
    $items = $itemsStmt->fetchAll();
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fotobox – Meine Bestellung</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
    <h1>Meine Bestellung</h1>
    <p><a href="index.php">Zeitfenster</a> · <a href="all.php">Alle Fotos</a></p>

    <form id="nameForm">
        <label for="order_name">Name:</label>
        <input id="order_name" name="guest_name" maxlength="80" value="<?= htmlspecialchars((string) ($order['guest_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <button class="button" type="submit">Name speichern</button>
    </form>

    <p class="muted">ZIP-Download ist als stabiler Platzhalter vorgesehen und nicht implementiert.</p>

    <section class="grid">
        <?php foreach ($items as $item): ?>
            <article class="card">
                <img src="media.php?type=thumb&t=<?= urlencode($item['token']) ?>" alt="Bestellfoto">
                <small><?= date('d.m. H:i', (int) $item['ts']) ?></small>
                <a class="button" href="media.php?type=download&t=<?= urlencode($item['token']) ?>">Download</a>
                <button class="button danger" data-unmark-token="<?= htmlspecialchars($item['token'], ENT_QUOTES, 'UTF-8') ?>">Entfernen</button>
            </article>
        <?php endforeach; ?>
    </section>

    <p id="orderMessage" class="muted"></p>
</main>
<script src="app.js"></script>
</body>
</html>
