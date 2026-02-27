<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();

$pdo = pdo();
$sessionToken = getOrCreateSessionToken();
$order = getOpenOrder($pdo, $sessionToken, true);

$orderItems = [];
if ($order !== null) {
    $stmt = $pdo->prepare('SELECT p.token, p.ts FROM order_items oi JOIN photos p ON p.id = oi.photo_id WHERE oi.order_id = :orderId AND p.deleted = 0 ORDER BY p.ts DESC');
    $stmt->execute([':orderId' => $order['id']]);
    $orderItems = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meine Bestellung</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
    <h1>Meine Bestellung</h1>
    <p class="nav-links"><a href="index.php">Zur Galerie</a> <a href="all.php">Alle Fotos</a></p>

    <form method="post" action="api_order_name.php" class="panel form-stack">
        <label for="guest_name">Name</label>
        <input id="guest_name" name="guest_name" maxlength="80" value="<?= htmlspecialchars((string) ($order['guest_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Namen speichern</button>
    </form>

    <section class="grid">
        <?php foreach ($orderItems as $item): ?>
            <article class="card card-panel">
                <a href="photo.php?t=<?= urlencode((string) $item['token']) ?>">
                    <img src="image.php?t=<?= urlencode((string) $item['token']) ?>&amp;type=thumb" alt="Ausgewähltes Foto" loading="lazy">
                </a>
                <span><?= date('d.m. H:i', (int) $item['ts']) ?></span>
                <form method="post" action="api_unmark.php">
                    <input type="hidden" name="t" value="<?= htmlspecialchars((string) $item['token'], ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit">Entfernen</button>
                </form>
            </article>
        <?php endforeach; ?>
    </section>

    <div class="panel">
        <button type="button" disabled>ZIP Download (Placeholder)</button>
    </div>
</main>
</body>
</html>
