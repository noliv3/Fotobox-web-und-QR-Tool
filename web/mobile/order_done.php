<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';
require_once __DIR__ . '/_layout.php';

noCacheHeaders();
noIndexHeaders();

$pdo = pdo();
$orderId = (int) ($_GET['id'] ?? 0);

$order = null;
if ($orderId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $orderId]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $order = $row;
    }
}

ob_start();
if ($order === null) {
    ?>
    <div class="empty-state">
        <p>Bestellung nicht gefunden</p>
        <p><a href="/mobile/">Zurueck zur Startseite</a></p>
    </div>
    <?php
} else {
    ?>
    <div class="panel">
        <h2>Bestellung abgeschlossen</h2>
        <p><strong>Bestellnummer:</strong> #<?= (int) $order['id'] ?></p>
        <p><strong>Zeitpunkt:</strong> <?= mobileEsc((string) ($order['created_at'] ?? date('c', (int) $order['created_ts']))) ?></p>
        <p><strong>Name:</strong> <?= mobileEsc((string) ($order['name'] ?? $order['guest_name'] ?? '')) ?></p>
        <p><strong>Anzahl Fotos:</strong> <?= (int) ($order['count'] ?? 0) ?></p>
        <p><strong>Versand:</strong> <?= ((int) ($order['shipping_enabled'] ?? 0)) === 1 ? 'Ja' : 'Nein' ?></p>
        <p><strong>Gesamt:</strong> <?= number_format((float) ($order['price_total'] ?? 0), 2, ',', '.') ?> EUR</p>
        <p><a href="/mobile/">Zurueck zur Startseite</a></p>
    </div>
    <?php
}
$content = (string) ob_get_clean();

mobileRenderLayout([
    'title' => 'Bestellung',
    'status_line' => 'Bestellung abgeschlossen',
    'active_view' => 'favs',
    'content_html' => $content,
]);
