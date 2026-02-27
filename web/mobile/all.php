<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);

$pdo = app_pdo();
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;
$offset = ($page - 1) * $perPage;

$total = (int) $pdo->query('SELECT COUNT(*) FROM photos WHERE deleted = 0')->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = $pdo->prepare('SELECT ts, token FROM photos WHERE deleted = 0 ORDER BY ts DESC LIMIT :limit OFFSET :offset');
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$photos = $stmt->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fotobox – Alle Fotos</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
    <h1>Alle Fotos</h1>
    <p><a href="index.php">Zeitfenster-Galerie</a> · <a href="order.php">Meine Bestellung</a></p>

    <section class="grid">
        <?php foreach ($photos as $photo): ?>
            <a class="card" href="photo.php?t=<?= urlencode($photo['token']) ?>">
                <img src="media.php?type=thumb&t=<?= urlencode($photo['token']) ?>" alt="Foto">
                <small><?= date('d.m. H:i', (int) $photo['ts']) ?></small>
            </a>
        <?php endforeach; ?>
    </section>

    <nav class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>">Zurück</a>
        <?php endif; ?>
        <span>Seite <?= $page ?> / <?= $pages ?></span>
        <?php if ($page < $pages): ?>
            <a href="?page=<?= $page + 1 ?>">Weiter</a>
        <?php endif; ?>
    </nav>
</main>
</body>
</html>
