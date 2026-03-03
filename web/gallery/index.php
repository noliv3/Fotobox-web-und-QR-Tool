<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();

$pdo = pdo();
$now = date('Y-m-d H:i:s');
$photoCount = (int) $pdo->query('SELECT COUNT(*) FROM photos WHERE deleted = 0')->fetchColumn();
$lastImportTs = (int) $pdo->query('SELECT COALESCE(MAX(ts),0) FROM photos WHERE deleted = 0')->fetchColumn();
$photos = $pdo->query('SELECT token, ts FROM photos WHERE deleted = 0 ORDER BY ts DESC LIMIT 12')->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Galerie Status</title>
    <link rel="stylesheet" href="/gallery/style.css">
</head>
<body>
<main class="container">
    <h1>Galerie</h1>
    <section class="panel">
        <p><strong>Uhrzeit:</strong> <?= htmlspecialchars($now, ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Anzahl Fotos:</strong> <?= $photoCount ?></p>
        <p><strong>Letzter Import:</strong> <?= $lastImportTs > 0 ? date('Y-m-d H:i:s', $lastImportTs) : 'n/a' ?></p>
    </section>

    <section class="panel">
        <h2>Letzte 12 Fotos</h2>
        <div class="photo-grid">
            <?php foreach ($photos as $photo): ?>
                <a class="photo-card" href="/mobile/photo.php?t=<?= urlencode((string) $photo['token']) ?>">
                    <img src="/mobile/image.php?t=<?= urlencode((string) $photo['token']) ?>&amp;type=thumb" alt="Foto" loading="lazy">
                    <span><?= date('d.m. H:i', (int) $photo['ts']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

</main>
</body>
</html>
