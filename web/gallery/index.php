<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();

$pdo = pdo();
$oneHourAgo = nowTs() - 3600;

$counts = [
    'total_photos' => (int) $pdo->query('SELECT COUNT(*) FROM photos WHERE deleted = 0')->fetchColumn(),
    'jobs_pending' => (int) $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status = 'pending'")->fetchColumn(),
    'jobs_printing' => (int) $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status = 'printing'")->fetchColumn(),
    'jobs_done' => (int) $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status = 'done'")->fetchColumn(),
    'jobs_error' => (int) $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status = 'error'")->fetchColumn(),
];

$stmtLastHour = $pdo->prepare('SELECT COUNT(*) FROM photos WHERE deleted = 0 AND ts >= :minTs');
$stmtLastHour->execute([':minTs' => $oneHourAgo]);
$counts['last_hour'] = (int) $stmtLastHour->fetchColumn();

$lastPhotos = $pdo->query('SELECT token, ts FROM photos WHERE deleted = 0 ORDER BY ts DESC LIMIT 12')->fetchAll();
$lastJobs = $pdo->query('SELECT id, status, error, created_ts FROM print_jobs ORDER BY id DESC LIMIT 12')->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Galerie Status</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
    <header class="header-row">
        <h1>Galerie Statusseite</h1>
        <a href="admin.php">Admin</a>
    </header>

    <section class="panel stats-grid">
        <div><strong>Fotos gesamt</strong><span><?= $counts['total_photos'] ?></span></div>
        <div><strong>Fotos letzte Stunde</strong><span><?= $counts['last_hour'] ?></span></div>
        <div><strong>Jobs pending</strong><span><?= $counts['jobs_pending'] ?></span></div>
        <div><strong>Jobs printing</strong><span><?= $counts['jobs_printing'] ?></span></div>
        <div><strong>Jobs done</strong><span><?= $counts['jobs_done'] ?></span></div>
        <div><strong>Jobs error</strong><span><?= $counts['jobs_error'] ?></span></div>
    </section>

    <section class="panel">
        <h2>Letzte 12 Fotos</h2>
        <div class="photo-grid">
            <?php foreach ($lastPhotos as $photo): ?>
                <a class="photo-card" href="../mobile/photo.php?t=<?= urlencode((string) $photo['token']) ?>">
                    <img src="../mobile/image.php?t=<?= urlencode((string) $photo['token']) ?>&amp;type=thumb" alt="Thumb" loading="lazy">
                    <span><?= date('d.m. H:i', (int) $photo['ts']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <h2>Letzte 12 Jobs</h2>
        <ul class="job-list">
            <?php foreach ($lastJobs as $job): ?>
                <li>
                    <strong>#<?= (int) $job['id'] ?></strong>
                    <span><?= htmlspecialchars((string) $job['status'], ENT_QUOTES, 'UTF-8') ?></span>
                    <small><?= date('Y-m-d H:i:s', (int) $job['created_ts']) ?></small>
                    <em><?= htmlspecialchars(mb_substr((string) ($job['error'] ?? ''), 0, 80), ENT_QUOTES, 'UTF-8') ?></em>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</main>
</body>
</html>
