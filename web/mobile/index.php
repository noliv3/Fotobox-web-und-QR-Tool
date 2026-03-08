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

        $stmt = $pdo->prepare('SELECT id, token, ts, created_at FROM photos WHERE deleted = 0 AND created_at BETWEEN :fromTs AND :toTs ORDER BY created_at DESC LIMIT 240');
        $stmt->execute([':fromTs' => $fromTs, ':toTs' => $toTs]);
        $photos = $stmt->fetchAll();
    } else {
        $minTs = $now - ($windowMinutes * 60);
        $statusLine = 'Letzte ' . $windowMinutes . ' Minuten';

        $stmt = $pdo->prepare('SELECT id, token, ts, created_at FROM photos WHERE deleted = 0 AND created_at >= :minTs ORDER BY created_at DESC LIMIT 240');
        $stmt->execute([':minTs' => $minTs]);
        $photos = $stmt->fetchAll();
    }
} elseif ($view === 'all') {
    $statusLine = 'Alle Fotos';
    $photos = $pdo->query('SELECT id, token, ts, created_at FROM photos WHERE deleted = 0 ORDER BY created_at DESC LIMIT 600')->fetchAll();
} else {
    $statusLine = 'Merkliste';
    $favIds = array_keys($_SESSION['favs']);
    if ($favIds !== []) {
        $placeholders = implode(',', array_fill(0, count($favIds), '?'));
        $stmt = $pdo->prepare('SELECT id, token, ts, created_at FROM photos WHERE deleted = 0 AND id IN (' . $placeholders . ') ORDER BY created_at DESC');
        $stmt->execute($favIds);
        $photos = $stmt->fetchAll();
    }
}

$printableFavCount = 0;
if ($view === 'favs' && $photos !== []) {
    foreach ($photos as $photo) {
        if (is_photo_printable($photo)) {
            $printableFavCount++;
        }
    }
}

$currentListParams = [];
if ($view !== 'recent') {
    $currentListParams['view'] = $view;
} elseif ($timeFilter !== '' && validateTimeHHMM($timeFilter)) {
    $currentListParams['view'] = 'recent';
    $currentListParams['time'] = $timeFilter;
}
$currentListUrl = '/mobile/';
if ($currentListParams !== []) {
    $currentListUrl .= '?' . http_build_query($currentListParams);
}

ob_start();
echo '<div data-gallery-list>';
if ($view === 'favs') {
    echo '<div class="panel actions">';
    if ($photos !== []) {
        echo '<a class="button" href="/mobile/download_zip.php">Alle als ZIP</a>';
    }
    if ($printableFavCount >= 2) {
        echo '<form method="post" action="/mobile/api_print_favs.php" data-print-favs-form style="margin:0;">';
        echo '<input type="hidden" name="csrf_token" value="' . mobileEsc($csrfToken) . '">';
        echo '<button type="submit">2 Gemerkte drucken</button>';
        echo '</form>';
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
            $photoCreatedAt = (int) ($photo['created_at'] ?? $photo['ts'] ?? 0);
            $isNew = ($now - $photoCreatedAt) <= ($windowMinutes * 60);
            $photoLinkParams = [
                'id' => (string) $photo['id'],
                'view' => $view,
                'return' => $currentListUrl,
            ];
            if ($view === 'recent' && $timeFilter !== '' && validateTimeHHMM($timeFilter)) {
                $photoLinkParams['time'] = $timeFilter;
            }
            $photoLink = '/mobile/photo.php?' . http_build_query($photoLinkParams);
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
                <a class="tile-link" href="<?= mobileEsc($photoLink) ?>" data-photo-link>
                    <img src="/mobile/image.php?id=<?= urlencode((string) $photo['id']) ?>&amp;type=thumb" alt="Foto" loading="lazy" decoding="async" fetchpriority="low">
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
echo '</div>';
$content = (string) ob_get_clean();

mobileRenderLayout([
    'title' => 'Photobox',
    'status_line' => $statusLine,
    'active_view' => $view,
    'content_html' => $content,
]);
