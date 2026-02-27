<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();

$pdo = pdo();
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 60;
$offset = ($page - 1) * $pageSize;

$total = (int) $pdo->query('SELECT COUNT(*) FROM photos WHERE deleted = 0')->fetchColumn();
$pages = max(1, (int) ceil($total / $pageSize));

$stmt = $pdo->prepare('SELECT token, ts FROM photos WHERE deleted = 0 ORDER BY ts DESC LIMIT :limit OFFSET :offset');
$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$photos = $stmt->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Alle Fotos</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
    <h1>Alle Fotos</h1>
    <p class="nav-links"><a href="index.php">Zur Galerie</a> <a href="order.php">Meine Bestellung</a></p>

    <section class="grid">
        <?php foreach ($photos as $photo): ?>
            <a class="card" href="photo.php?t=<?= urlencode((string) $photo['token']) ?>">
                <img src="image.php?t=<?= urlencode((string) $photo['token']) ?>&amp;type=thumb" alt="Foto-Thumbnail" loading="lazy">
                <span><?= date('d.m. H:i', (int) $photo['ts']) ?></span>
            </a>
        <?php endforeach; ?>
    </section>

    <nav class="pagination panel">
        <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">Zurück</a><?php endif; ?>
        <span>Seite <?= $page ?> / <?= $pages ?></span>
        <?php if ($page < $pages): ?><a href="?page=<?= $page + 1 ?>">Weiter</a><?php endif; ?>
    </nav>
</main>
</body>
</html>
