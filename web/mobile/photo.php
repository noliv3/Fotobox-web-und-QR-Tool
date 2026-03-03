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
    $printConfigured = isPrintConfigured($cfg);
    $isFav = isset($_SESSION['favs'][(string) $photo['id']]);
    $printTicket = createPrintTicket((string) $photo['id']);
    ?>
    <img class="detail-image" src="/mobile/image.php?id=<?= urlencode((string) $photo['id']) ?>&amp;type=original" alt="Foto">
    <div class="panel muted">Aufnahme: <?= date('d.m.Y H:i:s', (int) $photo['ts']) ?></div>
    <div class="panel actions">
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
    <?php
    }
}
$content = (string) ob_get_clean();

mobileRenderLayout([
    'title' => 'Foto',
    'status_line' => 'Fotoansicht',
    'active_view' => 'recent',
    'content_html' => $content,
]);
