<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();

session_name('pb_gallery');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrfToken = (string) $_SESSION['csrf_token'];

$pdo = pdo();
$now = date('Y-m-d H:i:s');
$photoCount = (int) $pdo->query('SELECT COUNT(*) FROM photos WHERE deleted = 0')->fetchColumn();
$lastImportTs = (int) $pdo->query('SELECT COALESCE(MAX(ts),0) FROM photos WHERE deleted = 0')->fetchColumn();
$photos = $pdo->query('SELECT id, token, ts FROM photos WHERE deleted = 0 ORDER BY ts DESC LIMIT 12')->fetchAll();

$openPrintJobs = (int) $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status IN ('queued','spooled','needs_attention')")->fetchColumn();
$needsAttentionJobs = $pdo->query("SELECT id, last_error, created_ts FROM print_jobs WHERE status = 'needs_attention' ORDER BY updated_at DESC, id DESC LIMIT 5")->fetchAll();
$lastJobs = $pdo->query('SELECT id, status, created_ts FROM print_jobs ORDER BY id DESC LIMIT 20')->fetchAll();

$heartCounts = [];
if ($photos !== []) {
    $heartStmt = $pdo->prepare('SELECT value FROM kv WHERE key = :key LIMIT 1');
    foreach ($photos as $photo) {
        $photoId = (string) ($photo['id'] ?? '');
        if ($photoId === '') {
            continue;
        }
        $heartStmt->execute([':key' => 'heart_total_' . $photoId]);
        $heartCounts[$photoId] = (int) ($heartStmt->fetchColumn() ?: 0);
    }
}

function printErrorLabel(string $error): string
{
    return match ($error) {
        'PAPER_OUT' => 'Papier leer',
        'OFFLINE' => 'Drucker offline',
        'PAUSED' => 'Drucker pausiert',
        default => $error !== '' ? $error : 'Unbekannt',
    };
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Galerie Status</title>
    <link rel="stylesheet" href="/gallery/style.css">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
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
        <h2>Druckstatus</h2>
        <p><strong>Offene Druckjobs:</strong> <?= $openPrintJobs ?></p>
        <h3>Needs Attention (letzte 5)</h3>
        <ul>
            <?php if ($needsAttentionJobs === []): ?>
                <li>Keine</li>
            <?php else: ?>
                <?php foreach ($needsAttentionJobs as $job): ?>
                    <li>#<?= (int) $job['id'] ?> – <?= htmlspecialchars(printErrorLabel((string) ($job['last_error'] ?? '')), ENT_QUOTES, 'UTF-8') ?> (<?= date('d.m. H:i', (int) $job['created_ts']) ?>)</li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>

        <h3>Letzte 20 Jobs</h3>
        <table>
            <thead>
            <tr><th>ID</th><th>Status</th><th>Erstellt</th></tr>
            </thead>
            <tbody>
            <?php foreach ($lastJobs as $job): ?>
                <tr>
                    <td>#<?= (int) $job['id'] ?></td>
                    <td><?= htmlspecialchars((string) $job['status'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= date('Y-m-d H:i:s', (int) $job['created_ts']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <h2>Letzte 12 Fotos</h2>
        <div class="photo-grid">
            <?php foreach ($photos as $photo): ?>
                <article class="photo-item">
                    <a class="photo-card" href="/mobile/photo.php?t=<?= urlencode((string) $photo['token']) ?>">
                        <img src="/mobile/image.php?t=<?= urlencode((string) $photo['token']) ?>&amp;type=thumb" alt="Foto" loading="lazy">
                        <span><?= date('d.m. H:i', (int) $photo['ts']) ?></span>
                    </a>
                    <button type="button" class="heart-button" data-heart-button data-photo-id="<?= htmlspecialchars((string) $photo['id'], ENT_QUOTES, 'UTF-8') ?>">
                        ❤️ <span data-heart-count><?= (int) ($heartCounts[(string) $photo['id']] ?? 0) ?></span>
                    </button>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

</main>
<script src="/gallery/app.js" defer></script>
</body>
</html>
