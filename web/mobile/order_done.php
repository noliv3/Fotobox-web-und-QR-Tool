<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';
require_once __DIR__ . '/_layout.php';

noCacheHeaders();
noIndexHeaders();
initMobileSession();

$pdo = pdo();
$orderToken = trim((string) ($_GET['o'] ?? ''));

$order = null;
if ($orderToken !== '') {
    $stmt = $pdo->prepare('SELECT id, created_at, photo_count, shipping_enabled, price_cents, paypal_url, zip_path FROM orders WHERE order_token = :token LIMIT 1');
    $stmt->execute([':token' => $orderToken]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $order = $row;
    }
}

ob_start();
if ($order === null) {
    ?>
    <div class="empty-state">
        <p>Bestellung nicht gefunden.</p>
        <p><a href="/mobile/">Zurueck zur Startseite</a></p>
    </div>
    <?php
} else {
    $priceCents = (int) ($order['price_cents'] ?? 0);
    $paypalUrl = trim((string) ($order['paypal_url'] ?? ''));
    if ($paypalUrl === '') {
        $paypalUrl = 'https://paypal.me/noliv3/' . number_format($priceCents / 100, 2, '.', '');
    }
    ?>
    <div class="panel">
        <h2>Bestellung abgeschlossen</h2>
        <div class="order-done-summary">
            <p><strong>Bestellnummer:</strong> #<?= (int) $order['id'] ?></p>
            <p><strong>Datum:</strong> <?= date('d.m.Y H:i', (int) ($order['created_at'] ?? nowTs())) ?></p>
            <p><strong>Anzahl Fotos:</strong> <?= (int) ($order['photo_count'] ?? 0) ?></p>
            <p><strong>Typ:</strong> <?= ((int) ($order['shipping_enabled'] ?? 0)) === 1 ? 'Versand' : 'Abholung' ?></p>
            <p><strong>Preis:</strong> <?= number_format($priceCents / 100, 2, ',', '.') ?> EUR</p>
        </div>

        <div class="payment-steps">
            <h3>Zahlung in 3 Schritten</h3>

            <div class="payment-step">
                <span class="step-no">1</span>
                <div>
                    <strong>WLAN trennen</strong>
                    <p>Bitte Photobox-WLAN kurz verlassen und Mobilfunk aktivieren.</p>
                </div>
            </div>

            <div class="payment-step">
                <span class="step-no">2</span>
                <div>
                    <strong>PayPal oeffnen</strong>
                    <p>Der Link enthaelt den Betrag bereits vorausgefuellt.</p>
                    <p>
                        <a class="button" href="<?= mobileEsc($paypalUrl) ?>" target="_blank" rel="noopener noreferrer">PayPal jetzt oeffnen</a>
                    </p>
                    <p class="muted"><a href="<?= mobileEsc($paypalUrl) ?>" target="_blank" rel="noopener noreferrer"><?= mobileEsc($paypalUrl) ?></a></p>
                </div>
            </div>

            <div class="payment-step">
                <span class="step-no">3</span>
                <div>
                    <strong>Betrag bestaetigen und senden</strong>
                    <p>Bitte genau <strong><?= number_format($priceCents / 100, 2, ',', '.') ?> EUR</strong> bezahlen.</p>
                </div>
            </div>
        </div>

        <p><img src="/mobile/qr.php?d=<?= urlencode($paypalUrl) ?>" alt="PayPal QR-Code" width="220" height="220"></p>

        <?php if (trim((string) ($order['zip_path'] ?? '')) === ''): ?>
            <p>ZIP derzeit nicht verfuegbar.</p>
        <?php endif; ?>

        <p>Zahlung benoetigt Internet. Falls kein Netz: kurz WLAN wechseln und dann den PayPal-Button nutzen.</p>
        <p>Bestellungen werden nur mit vollstaendiger Adresse + E-Mail erfuellt.</p>
        <p>Nachtraegliche Aenderungen sind nur innerhalb von 24 Stunden moeglich.</p>
    </div>
    <?php
}
$content = (string) ob_get_clean();

mobileRenderLayout([
    'title' => 'Bestellung abgeschlossen',
    'status_line' => 'Bestellung abgeschlossen',
    'active_view' => 'favs',
    'content_html' => $content,
]);
