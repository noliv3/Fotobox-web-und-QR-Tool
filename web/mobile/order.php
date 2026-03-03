<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';
require_once __DIR__ . '/_layout.php';

noCacheHeaders();
noIndexHeaders();

initMobileSession();
$csrfToken = getCsrfToken();
$pdo = pdo();
$cfg = config();

function orderEsc(string $value): string
{
    return mobileEsc($value);
}

function orderPriceCents(int $count, bool $shippingEnabled): int
{
    $perPhoto = $count >= 10 ? 50 : 100;
    return ($count * $perPhoto) + ($shippingEnabled ? 300 : 0);
}

function formatEurFromCents(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.');
}

function orderErrorPage(string $title, string $message): void
{
    ob_start();
    ?>
    <div class="empty-state">
        <p><strong><?= orderEsc($title) ?></strong></p>
        <p><?= orderEsc($message) ?></p>
        <p><a href="/mobile/order.php">Zurueck zur Bestellung</a></p>
    </div>
    <?php
    $content = (string) ob_get_clean();

    mobileRenderLayout([
        'title' => 'Bestellung',
        'status_line' => 'Bestellung',
        'active_view' => 'favs',
        'content_html' => $content,
    ]);
    exit;
}

$favIds = array_values(array_keys($_SESSION['favs'] ?? []));
$photos = [];
if ($favIds !== []) {
    $placeholders = implode(',', array_fill(0, count($favIds), '?'));
    $stmt = $pdo->prepare('SELECT id, token, ts, filename FROM photos WHERE deleted = 0 AND id IN (' . $placeholders . ') ORDER BY ts DESC');
    $stmt->execute($favIds);
    $photos = $stmt->fetchAll();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $rateKey = 'rl_order_submit_' . getClientIp();
    if (!rateLimitCheck($pdo, $rateKey, (int) $cfg['rate_limit_max'], (int) $cfg['rate_limit_window_seconds'])) {
        orderErrorPage('Zu viele Anfragen', 'Bitte kurz warten und danach erneut versuchen.');
    }

    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        orderErrorPage('Ungueltige Anfrage', 'Das Formular konnte nicht bestaetigt werden.');
    }

    if ($photos === []) {
        orderErrorPage('Merkliste ist leer', 'Bitte zuerst Fotos markieren.');
    }

    $name = sanitizeGuestName((string) ($_POST['name'] ?? ''));
    $email = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 160);
    $shippingEnabled = (($_POST['shipping_enabled'] ?? '') === '1');

    $addrStreet = mb_substr(trim((string) ($_POST['addr_street'] ?? '')), 0, 160);
    $addrZip = mb_substr(trim((string) ($_POST['addr_zip'] ?? '')), 0, 30);
    $addrCity = mb_substr(trim((string) ($_POST['addr_city'] ?? '')), 0, 120);
    $addrCountry = mb_substr(trim((string) ($_POST['addr_country'] ?? '')), 0, 120);

    if ($name === '' || $email === '') {
        orderErrorPage('Pflichtfelder fehlen', 'Name und E-Mail sind erforderlich.');
    }

    if ($shippingEnabled && ($addrStreet === '' || $addrZip === '' || $addrCity === '' || $addrCountry === '')) {
        orderErrorPage('Unvollstaendige Versanddaten', 'Fuer Versand werden Strasse+Nr, PLZ, Ort und Land benoetigt.');
    }

    $maxAgeHours = max(1, (int) ($cfg['order_max_age_hours'] ?? 24));
    $maxAgeSeconds = $maxAgeHours * 3600;
    $oldestAllowedTs = nowTs() - $maxAgeSeconds;
    foreach ($photos as $photo) {
        if ((int) ($photo['ts'] ?? 0) < $oldestAllowedTs) {
            orderErrorPage('Bestellung nicht mehr moeglich', 'Mindestens ein Bild ist aelter als ' . $maxAgeHours . ' Stunden.');
        }
    }

    $photoCount = count($photos);
    $priceCents = orderPriceCents($photoCount, $shippingEnabled);
    $orderToken = bin2hex(random_bytes(16));

    $baseUrl = rtrim((string) ($cfg['paypal_me_base_url'] ?? ''), '/');
    $paypalUrl = $baseUrl !== '' ? $baseUrl . '/' . number_format($priceCents / 100, 2, '.', '') : null;

    $orderId = 0;
    $zipPath = null;

    try {
        $pdo->beginTransaction();

        $insertOrder = $pdo->prepare(
            'INSERT INTO orders(created_at, name, email, shipping_enabled, addr_street, addr_zip, addr_city, addr_country, photo_count, price_cents, paypal_url, pay_status, order_token, status)
             VALUES(:createdAt, :name, :email, :shippingEnabled, :addrStreet, :addrZip, :addrCity, :addrCountry, :photoCount, :priceCents, :paypalUrl, :payStatus, :orderToken, :status)'
        );
        $insertOrder->execute([
            ':createdAt' => nowTs(),
            ':name' => $name,
            ':email' => $email,
            ':shippingEnabled' => $shippingEnabled ? 1 : 0,
            ':addrStreet' => $shippingEnabled ? $addrStreet : null,
            ':addrZip' => $shippingEnabled ? $addrZip : null,
            ':addrCity' => $shippingEnabled ? $addrCity : null,
            ':addrCountry' => $shippingEnabled ? $addrCountry : null,
            ':photoCount' => $photoCount,
            ':priceCents' => $priceCents,
            ':paypalUrl' => $paypalUrl,
            ':payStatus' => 'unpaid',
            ':orderToken' => $orderToken,
            ':status' => 'submitted',
        ]);

        $orderId = (int) $pdo->lastInsertId();
        $insertItem = $pdo->prepare('INSERT INTO order_items(order_id, photo_id, created_at) VALUES(:orderId, :photoId, :createdAt)');

        foreach ($photos as $photo) {
            $insertItem->execute([
                ':orderId' => $orderId,
                ':photoId' => (string) $photo['id'],
                ':createdAt' => nowTs(),
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        orderErrorPage('Bestellung fehlgeschlagen', 'Die Bestellung konnte nicht gespeichert werden.');
    }

    if (class_exists('ZipArchive') && $orderId > 0) {
        try {
            $orderBaseDir = rtrim(pathOrders(), '/\\') . '/' . $orderId;
            ensureDir($orderBaseDir);
            $zipPath = $orderBaseDir . '/order_' . $orderId . '.zip';

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                foreach ($photos as $photo) {
                    $source = pathOriginals() . '/' . (string) $photo['id'] . '.jpg';
                    if (!is_file($source)) {
                        continue;
                    }
                    $zip->addFile($source, basename($source));
                }
                $zip->close();
                if (is_file($zipPath)) {
                    $updateZip = $pdo->prepare('UPDATE orders SET zip_path = :zipPath WHERE id = :id');
                    $updateZip->execute([':zipPath' => $zipPath, ':id' => $orderId]);
                }
            }
        } catch (Throwable $e) {
            // Bestellfluss bleibt erfolgreich, ZIP bleibt optional.
        }
    }

    $_SESSION['favs'] = [];
    header('Location: /mobile/order_done.php?o=' . urlencode($orderToken), true, 302);
    exit;
}

$totalNoShipping = orderPriceCents(count($photos), false);

ob_start();
if ($photos === []) {
    ?>
    <div class="empty-state">
        <p>Merkliste ist leer.</p>
        <p><a href="/mobile/">Zurueck zur Startseite</a></p>
    </div>
    <?php
} else {
    ?>
    <form method="post" class="panel" data-order-form>
        <input type="hidden" name="csrf_token" value="<?= orderEsc($csrfToken) ?>">

        <label for="name">Name *</label>
        <input id="name" name="name" type="text" maxlength="80" required>

        <label for="email">E-Mail *</label>
        <input id="email" name="email" type="email" maxlength="160" required>

        <p><label><input type="checkbox" name="shipping_enabled" value="1" data-shipping-toggle> Versand</label></p>

        <div class="panel order-shipping-fields" data-shipping-fields hidden>
            <label for="addr_street">Strasse + Nr *</label>
            <input id="addr_street" name="addr_street" type="text" maxlength="160">

            <label for="addr_zip">PLZ *</label>
            <input id="addr_zip" name="addr_zip" type="text" maxlength="30">

            <label for="addr_city">Ort *</label>
            <input id="addr_city" name="addr_city" type="text" maxlength="120">

            <label for="addr_country">Land *</label>
            <input id="addr_country" name="addr_country" type="text" maxlength="120">
        </div>

        <div class="panel">
            <p><strong>Preis:</strong> <?= formatEurFromCents($totalNoShipping) ?> EUR ohne Versand</p>
            <p>Bis 9 Fotos: 1,00 EUR/Fotos, ab 10 Fotos: 0,50 EUR/Fotos, Versand +3,00 EUR.</p>
            <p>Abholung der Bilder beim Brautpaar oder Versand durch Brautpaar moeglich.</p>
            <p>Bestellungen werden nur mit vollstaendiger Adresse + E-Mail erfuellt.</p>
            <p>Nachtraegliche Aenderungen sind nur innerhalb von <?= (int) ($cfg['order_max_age_hours'] ?? 24) ?> Stunden moeglich.</p>
        </div>

        <button type="submit">Bestellen</button>
    </form>

    <section class="grid">
        <?php foreach ($photos as $photo): ?>
            <article class="tile" data-photo-id="<?= orderEsc((string) $photo['id']) ?>">
                <a class="tile-link" href="/mobile/photo.php?id=<?= urlencode((string) $photo['id']) ?>">
                    <img src="/mobile/image.php?t=<?= urlencode((string) $photo['token']) ?>&amp;type=thumb" alt="Foto" loading="lazy">
                    <time><?= date('d.m. H:i', (int) $photo['ts']) ?></time>
                </a>
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
