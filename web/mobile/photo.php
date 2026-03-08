<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';
require_once __DIR__ . '/_layout.php';

noCacheHeaders();
noIndexHeaders();

initMobileSession();
$csrfToken = getCsrfToken();

$pdo = pdo();
$photoId = trim((string) ($_GET['id'] ?? ''));
$photoToken = trim((string) ($_GET['t'] ?? ''));
$view = trim((string) ($_GET['view'] ?? 'recent'));
$timeFilter = trim((string) ($_GET['time'] ?? ''));
$returnUrl = trim((string) ($_GET['return'] ?? ''));
if (!in_array($view, ['recent', 'all', 'favs'], true)) {
    $view = 'recent';
}

$photo = null;
if ($photoId !== '') {
    $stmt = $pdo->prepare('SELECT id, token, ts, filename FROM photos WHERE id = :id AND deleted = 0 LIMIT 1');
    $stmt->execute([':id' => $photoId]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $photo = $row;
    }
} elseif ($photoToken !== '' && isValidToken($photoToken)) {
    $stmt = $pdo->prepare('SELECT id, token, ts, filename FROM photos WHERE token = :token AND deleted = 0 LIMIT 1');
    $stmt->execute([':token' => $photoToken]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $photo = $row;
    }
}

$defaultReturnParams = [];
if ($view !== 'recent') {
    $defaultReturnParams['view'] = $view;
} elseif ($timeFilter !== '' && validateTimeHHMM($timeFilter)) {
    $defaultReturnParams['view'] = 'recent';
    $defaultReturnParams['time'] = $timeFilter;
}
$defaultReturnUrl = '/mobile/';
if ($defaultReturnParams !== []) {
    $defaultReturnUrl .= '?' . http_build_query($defaultReturnParams);
}
if ($returnUrl === '' || !str_starts_with($returnUrl, '/mobile/')) {
    $returnUrl = $defaultReturnUrl;
}

$contextPhotoIds = [];
$currentPhotoIndex = -1;
$photoMetrics = ['view_count' => 0, 'like_count' => 0];
$previousPhotoUrl = '';
$nextPhotoUrl = '';
$activeView = in_array($view, ['recent', 'all', 'favs'], true) ? $view : 'recent';

if ($photo !== null) {
    if ($view === 'recent') {
        if ($timeFilter !== '' && validateTimeHHMM($timeFilter)) {
            $targetTs = parseTimeToTsToday($timeFilter, (string) (config()['timezone'] ?? 'Europe/Vienna'));
            $fromTs = $targetTs - 600;
            $toTs = $targetTs + 600;
            $stmt = $pdo->prepare('SELECT id FROM photos WHERE deleted = 0 AND created_at BETWEEN :fromTs AND :toTs ORDER BY created_at DESC LIMIT 240');
            $stmt->execute([':fromTs' => $fromTs, ':toTs' => $toTs]);
            $contextPhotoIds = array_map('strval', array_column($stmt->fetchAll(), 'id'));
        } else {
            $windowMinutes = (int) (config()['gallery_window_minutes'] ?? 15);
            $minTs = nowTs() - ($windowMinutes * 60);
            $stmt = $pdo->prepare('SELECT id FROM photos WHERE deleted = 0 AND created_at >= :minTs ORDER BY created_at DESC LIMIT 240');
            $stmt->execute([':minTs' => $minTs]);
            $contextPhotoIds = array_map('strval', array_column($stmt->fetchAll(), 'id'));
        }
    } elseif ($view === 'all') {
        $stmt = $pdo->query('SELECT id FROM photos WHERE deleted = 0 ORDER BY created_at DESC LIMIT 600');
        $contextPhotoIds = array_map('strval', array_column($stmt->fetchAll(), 'id'));
    } else {
        $favIds = array_values(array_keys($_SESSION['favs'] ?? []));
        if ($favIds !== []) {
            $placeholders = implode(',', array_fill(0, count($favIds), '?'));
            $stmt = $pdo->prepare('SELECT id FROM photos WHERE deleted = 0 AND id IN (' . $placeholders . ') ORDER BY created_at DESC');
            $stmt->execute($favIds);
            $contextPhotoIds = array_map('strval', array_column($stmt->fetchAll(), 'id'));
        }
    }

    $currentPhotoIndex = array_search((string) $photo['id'], $contextPhotoIds, true);
    if ($currentPhotoIndex === false) {
        $currentPhotoIndex = -1;
    }

    $buildPhotoUrl = static function (string $id) use ($returnUrl, $view, $timeFilter): string {
        $params = [
            'id' => $id,
            'view' => $view,
            'return' => $returnUrl,
        ];
        if ($view === 'recent' && $timeFilter !== '' && validateTimeHHMM($timeFilter)) {
            $params['time'] = $timeFilter;
        }
        return '/mobile/photo.php?' . http_build_query($params);
    };

    if ($currentPhotoIndex > 0 && isset($contextPhotoIds[$currentPhotoIndex - 1])) {
        $previousPhotoUrl = $buildPhotoUrl($contextPhotoIds[$currentPhotoIndex - 1]);
    }
    if ($currentPhotoIndex >= 0 && isset($contextPhotoIds[$currentPhotoIndex + 1])) {
        $nextPhotoUrl = $buildPhotoUrl($contextPhotoIds[$currentPhotoIndex + 1]);
    }
}

ob_start();
if ($photo === null) {
    ?>
    <div class="empty-state">
        <p>Foto nicht gefunden</p>
        <p><a href="/mobile/">Zurueck zur Startseite</a></p>
    </div>
    <?php
} else {
    $filename = trim((string) ($photo['filename'] ?? ''));
    if ($filename === '') {
        $filename = (string) $photo['id'] . '.jpg';
    }
    $originalFile = resolvePathInDirectory(pathOriginals(), $filename);
    if ($originalFile === null) {
        ?>
        <div class="empty-state">
            <p>Datei fehlt</p>
            <p>Das Foto ist indexiert, aber die Bilddatei ist aktuell nicht verfuegbar.</p>
            <p><a href="/mobile/?view=all">Zu Alle</a></p>
        </div>
        <?php
    } else {
        $cfg = config();
        $printable = is_photo_printable($photo);
        $printConfigured = isPrintConfigured($cfg) || getConfiguredPrinterName($pdo) !== '';
        $isFav = isset($_SESSION['favs'][(string) $photo['id']]);
        $printTicket = createPrintTicket((string) $photo['id']);
        recordPhotoView($pdo, (string) $photo['id']);
        $metricsStmt = $pdo->prepare('SELECT view_count, like_count FROM photo_metrics WHERE photo_id = :photoId LIMIT 1');
        $metricsStmt->execute([':photoId' => (string) $photo['id']]);
        $metricsRow = $metricsStmt->fetch();
        if (is_array($metricsRow)) {
            $photoMetrics = [
                'view_count' => (int) ($metricsRow['view_count'] ?? 0),
                'like_count' => (int) ($metricsRow['like_count'] ?? 0),
            ];
        }
        ?>
        <section
            class="photo-viewer-page"
            data-photo-viewer
            data-prev-url="<?= mobileEsc($previousPhotoUrl) ?>"
            data-next-url="<?= mobileEsc($nextPhotoUrl) ?>"
            data-back-url="<?= mobileEsc($returnUrl) ?>"
        >
            <div class="photo-stage" data-viewer-stage>
                <?php if ($previousPhotoUrl !== ''): ?>
                    <button type="button" class="viewer-nav viewer-prev" data-viewer-prev aria-label="Vorheriges Bild">&lsaquo;</button>
                <?php endif; ?>
                <img
                    class="detail-image"
                    src="/mobile/image.php?id=<?= urlencode((string) $photo['id']) ?>&amp;type=original"
                    alt="Foto"
                    data-viewer-image
                >
                <?php if ($nextPhotoUrl !== ''): ?>
                    <button type="button" class="viewer-nav viewer-next" data-viewer-next aria-label="Nächstes Bild">&rsaquo;</button>
                <?php endif; ?>
                <div class="viewer-hint">Wischen für das nächste Bild, doppelt tippen zum Zoomen</div>
            </div>

            <div class="panel actions photo-toolbar">
                <button type="button" class="button button-muted" data-smart-back data-fallback-url="<?= mobileEsc($returnUrl) ?>">Zurück</button>
                <a class="button" href="/mobile/download.php?id=<?= urlencode((string) $photo['id']) ?>">Download</a>
                <?php if ($printable && $printConfigured): ?>
                    <form method="post" action="/mobile/api_print.php" class="actions" style="margin:0;" data-print-form>
                        <input type="hidden" name="t" value="<?= mobileEsc((string) $photo['token']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= mobileEsc($csrfToken) ?>">
                        <input type="hidden" name="print_ticket" value="<?= mobileEsc($printTicket) ?>">
                        <button type="submit">Drucken</button>
                    </form>
                <?php endif; ?>
                <button type="button" data-fav-toggle data-photo-id="<?= mobileEsc((string) $photo['id']) ?>"><?= $isFav ? 'Gemerkt' : 'Merken' ?></button>
            </div>

            <div class="panel muted photo-meta">
                <?php if ($currentPhotoIndex >= 0): ?>
                    <span>Bild <?= $currentPhotoIndex + 1 ?> / <?= count($contextPhotoIds) ?></span>
                <?php endif; ?>
                <span>Aufnahme: <?= date('d.m.Y H:i:s', (int) $photo['ts']) ?></span>
                <span>Klicks: <?= (int) $photoMetrics['view_count'] ?></span>
                <span>Likes: <?= (int) $photoMetrics['like_count'] ?></span>
            </div>
        </section>
        <?php
    }
}
$content = (string) ob_get_clean();

mobileRenderLayout([
    'title' => 'Foto',
    'status_line' => 'Fotoansicht',
    'active_view' => $activeView,
    'body_class' => 'page-photo',
    'content_html' => $content,
]);

