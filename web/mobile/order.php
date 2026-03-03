<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';
require_once __DIR__ . '/_layout.php';

noCacheHeaders();
noIndexHeaders();

initMobileSession();
$csrfToken = getCsrfToken();

$pdo = pdo();
$favIds = array_values(array_keys($_SESSION['favs']));
if ($favIds === []) {
    header('Location: /mobile/', true, 302);
    exit;
}

$photos = [];
if ($favIds !== []) {
    $placeholders = implode(',', array_fill(0, count($favIds), '?'));
    $stmt = $pdo->prepare('SELECT id, token, ts FROM photos WHERE deleted = 0 AND id IN (' . $placeholders . ') ORDER BY ts DESC');
    $stmt->execute($favIds);
    $photos = $stmt->fetchAll();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $content = '<div class="empty-state"><p>Ungueltige Anfrage</p><p><a href="/mobile/order.php">Zurueck</a></p></div>';
        mobileRenderLayout([
            'title' => 'Bestellung',
            'status_line' => 'Bestellung',
            'active_view' => 'favs',
            'content_html' => $content,
        ]);
        exit;
    }

    $name = sanitizeGuestName((string) ($_POST['name'] ?? ''));
    $shippingEnabled = (($_POST['shipping_enabled'] ?? '') === '1');

    if ($name !== '' && $photos !== []) {
        $count = count($photos);
        $photoPrice = $count >= 10 ? 0.5 : 1.0;
        $total = ($count * $photoPrice) + ($shippingEnabled ? 3.0 : 0.0);

        $sessionToken = getOrCreateSessionToken();
        $insert = $pdo->prepare(
            'INSERT INTO orders(created_ts, guest_name, session_token, status, note, created_at, name, count, shipping_enabled, price_total)
             VALUES(:createdTs, :guestName, :sessionToken, :status, :note, :createdAt, :name, :count, :shippingEnabled, :priceTotal)'
        );
        $insert->execute([
            ':createdTs' => nowTs(),
            ':guestName' => $name,
            ':sessionToken' => $sessionToken,
            ':status' => 'submitted',
            ':note' => $shippingEnabled ? 'shipping_enabled=1' : 'shipping_enabled=0',
            ':createdAt' => date('c'),
            ':name' => $name,
            ':count' => $count,
            ':shippingEnabled' => $shippingEnabled ? 1 : 0,
            ':priceTotal' => $total,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $itemInsert = $pdo->prepare('INSERT INTO order_items(order_id, photo_id) VALUES(:orderId, :photoId) ON CONFLICT(order_id, photo_id) DO NOTHING');
        foreach ($photos as $photo) {
            $itemInsert->execute([
                ':orderId' => $orderId,
                ':photoId' => $photo['id'],
            ]);
        }

        $_SESSION['favs'] = [];
        header('Location: /mobile/order_done.php?id=' . $orderId, true, 302);
        exit;
    }
}

$count = count($photos);
$photoPrice = $count >= 10 ? 0.5 : 1.0;
$totalWithoutShipping = $count * $photoPrice;

ob_start();
if ($photos === []) {
    ?>
    <div class="empty-state">
        <p>Merkliste ist leer</p>
        <p><a href="/mobile/">Zurueck zur Startseite</a></p>
    </div>
    <?php
} else {
    ?>
    <form method="post" class="panel">
        <input type="hidden" name="csrf_token" value="<?= mobileEsc($csrfToken) ?>">
        <label for="name">Name</label>
        <input id="name" name="name" type="text" maxlength="80" required>

        <div class="panel">
            <p>Preisinfo:</p>
            <p>1,00 EUR pro Foto bis 9</p>
            <p>ab 10: 0,50 EUR pro Foto</p>
            <p>Versand 3,00 EUR optional</p>
            <p>Abholung ist moeglich.</p>
            <p><strong>Zwischensumme:</strong> <?= number_format($totalWithoutShipping, 2, ',', '.') ?> EUR</p>
            <label><input type="checkbox" name="shipping_enabled" value="1"> Versand (+3,00 EUR)</label>
        </div>

        <button type="submit">Bestellung absenden</button>
    </form>

    <section class="grid">
        <?php foreach ($photos as $photo): ?>
            <article class="tile" data-photo-id="<?= mobileEsc((string) $photo['id']) ?>">
                <a class="tile-link" href="/mobile/photo.php?id=<?= urlencode((string) $photo['id']) ?>">
                    <img src="/mobile/image.php?t=<?= urlencode((string) $photo['token']) ?>&amp;type=thumb" alt="Foto" loading="lazy">
                    <time><?= date('d.m. H:i', (int) $photo['ts']) ?></time>
                </a>
                <div class="actions" style="padding:.35rem .35rem .45rem;">
                    <button type="button" class="button-danger" data-fav-remove data-photo-id="<?= mobileEsc((string) $photo['id']) ?>">Entfernen</button>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
    <?php
}
$content = (string) ob_get_clean();

mobileRenderLayout([
    'title' => 'Bestellung',
    'status_line' => 'Bestellung',
    'active_view' => 'favs',
    'content_html' => $content,
]);
