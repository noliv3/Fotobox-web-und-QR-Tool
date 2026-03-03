<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';
require_once __DIR__ . '/_layout.php';

noCacheHeaders();
noIndexHeaders();

initMobileSession();

$pdo = pdo();
$cfg = config();
$windowMinutes = (int) ($cfg['gallery_window_minutes'] ?? 15);
$view = (string) ($_GET['view'] ?? 'recent');
if (!in_array($view, ['recent', 'all', 'favs'], true)) {
    $view = 'recent';
}

$statusLine = 'Letzte 15 Minuten';
$photos = [];
$now = nowTs();
$timeFilter = trim((string) ($_GET['time'] ?? ''));

if ($view === 'recent') {
    if ($timeFilter !== '' && validateTimeHHMM($timeFilter)) {
        $targetTs = parseTimeToTsToday($timeFilter, (string) ($cfg['timezone'] ?? 'Europe/Vienna'));
        $fromTs = $targetTs - 600;
        $toTs = $targetTs + 600;
        $statusLine = 'Von ' . date('H:i', $fromTs) . ' bis ' . date('H:i', $toTs);

        $stmt = $pdo->prepare('SELECT id, token, ts FROM photos WHERE deleted = 0 AND ts BETWEEN :fromTs AND :toTs ORDER BY ts DESC LIMIT 240');
        $stmt->execute([':fromTs' => $fromTs, ':toTs' => $toTs]);
        $photos = $stmt->fetchAll();
    } else {
        $minTs = $now - ($windowMinutes * 60);
        $statusLine = 'Letzte ' . $windowMinutes . ' Minuten';

        $stmt = $pdo->prepare('SELECT id, token, ts FROM photos WHERE deleted = 0 AND ts >= :minTs ORDER BY ts DESC LIMIT 240');
        $stmt->execute([':minTs' => $minTs]);
        $photos = $stmt->fetchAll();
    }
} elseif ($view === 'all') {
    $statusLine = 'Alle Fotos';
    $photos = $pdo->query('SELECT id, token, ts FROM photos WHERE deleted = 0 ORDER BY ts DESC LIMIT 600')->fetchAll();
} else {
    $statusLine = 'Merkliste';
    $favIds = array_keys($_SESSION['favs']);
    if ($favIds !== []) {
        $placeholders = implode(',', array_fill(0, count($favIds), '?'));
        $stmt = $pdo->prepare('SELECT id, token, ts FROM photos WHERE deleted = 0 AND id IN (' . $placeholders . ') ORDER BY ts DESC');
        $stmt->execute($favIds);
        $photos = $stmt->fetchAll();
    }
}

ob_start();
if ($view === 'favs') {
    echo '<div class="panel actions">';
    if ($photos !== []) {
        echo '<a class="button" href="/mobile/download_zip.php">Alle als ZIP</a>';
    }
    echo '<a class="button button-muted" href="/mobile/order.php">Bestellung</a>';
    echo '</div>';
}

if ($photos === []) {
    if ($view === 'favs') {
        ?>
        <div class="empty-state">
            <p>Merkliste ist leer</p>
            <p class="muted">Laenger halten zum Merken</p>
            <p><a href="/mobile/">Zurueck zur Startseite</a></p>
        </div>
        <?php
    } else {
        ?>
        <div class="empty-state">
            <p>Keine Fotos gefunden</p>
            <p><a href="/mobile/">Zurueck zur Startseite</a></p>
        </div>
        <?php
    }
} else {
    ?>
    <section class="grid">
        <?php foreach ($photos as $photo): ?>
            <?php
            $isFav = isset($_SESSION['favs'][(string) $photo['id']]);
            $isNew = ($now - (int) $photo['ts']) <= ($windowMinutes * 60);
            ?>
            <article class="tile <?= $isFav ? 'is-fav' : '' ?>" data-photo-tile data-photo-id="<?= mobileEsc((string) $photo['id']) ?>">
                <?php if ($isNew): ?>
                    <span class="badge-new" aria-label="Neu">
                        <strong>NEU</strong>
                        <svg viewBox="0 0 64 64" aria-hidden="true" focusable="false">
                            <g fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="32" cy="32" r="6" fill="currentColor"></circle>
                                <circle cx="32" cy="18" r="8"></circle>
                                <circle cx="46" cy="24" r="8"></circle>
                                <circle cx="46" cy="40" r="8"></circle>
                                <circle cx="32" cy="46" r="8"></circle>
                                <circle cx="18" cy="40" r="8"></circle>
                                <circle cx="18" cy="24" r="8"></circle>
                            </g>
                        </svg>
                    </span>
                <?php endif; ?>
                <a class="tile-link" href="/mobile/photo.php?id=<?= urlencode((string) $photo['id']) ?>">
                    <img src="/mobile/image.php?t=<?= urlencode((string) $photo['token']) ?>&amp;type=thumb" alt="Foto" loading="lazy">
                    <time><?= date('d.m. H:i', (int) $photo['ts']) ?></time>
                </a>
                <?php if ($view === 'favs'): ?>
                    <div class="actions" style="padding:.35rem .35rem .45rem;">
                        <button class="button-danger" type="button" data-fav-remove data-photo-id="<?= mobileEsc((string) $photo['id']) ?>">Entfernen</button>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
    <?php
}
$content = (string) ob_get_clean();

mobileRenderLayout([
    'title' => 'Photobox',
    'status_line' => $statusLine,
    'active_view' => $view,
    'content_html' => $content,
]);
