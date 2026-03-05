<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';
require_once __DIR__ . '/_layout.php';

noCacheHeaders();
noIndexHeaders();
initMobileSession();

$pdo = pdo();
$cfg = config();
$csrfToken = getCsrfToken();

if (!isset($_SESSION['upload_print_items']) || !is_array($_SESSION['upload_print_items'])) {
    $_SESSION['upload_print_items'] = [];
}

function uploadPrintSessionDir(): string
{
    $sessionId = preg_replace('/[^a-zA-Z0-9]/', '', session_id());
    if (!is_string($sessionId) || $sessionId === '') {
        $sessionId = 'anon';
    }
    return pathData() . '/uploads/session_' . $sessionId;
}

function resolveUploadPrintFile(string $id, array $item): ?string
{
    $dir = uploadPrintSessionDir();
    $base = realpath($dir);
    if ($base === false) {
        return null;
    }

    $filename = basename((string) ($item['filename'] ?? ''));
    if ($filename === '') {
        return null;
    }
    $path = realpath($base . '/' . $filename);
    if ($path === false || !is_file($path)) {
        return null;
    }

    $prefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($path, $prefix) !== 0) {
        return null;
    }

    return $path;
}

function uploadPrintStoreItem(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [false, 'Bitte eine JPG- oder PNG-Datei auswählen.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return [false, 'Upload konnte nicht verarbeitet werden.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 15 * 1024 * 1024) {
        return [false, 'Datei ist zu groß (maximal 15 MB).'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) ($finfo->file($tmp) ?: '');
    $ext = '';
    if ($mime === 'image/jpeg') {
        $ext = 'jpg';
    } elseif ($mime === 'image/png') {
        $ext = 'png';
    } else {
        return [false, 'Nur JPG oder PNG ist erlaubt.'];
    }

    $id = bin2hex(random_bytes(12));
    $dir = uploadPrintSessionDir();
    ensureDir($dir);
    $target = $dir . '/' . $id . '.' . $ext;
    if (!@move_uploaded_file($tmp, $target)) {
        return [false, 'Datei konnte nicht gespeichert werden.'];
    }

    $_SESSION['upload_print_items'][$id] = [
        'id' => $id,
        'filename' => basename($target),
        'mime' => $mime,
        'created_ts' => nowTs(),
        'orig_name' => textSubstr((string) ($file['name'] ?? ''), 0, 120),
    ];

    return [true, 'Bild gespeichert.'];
}

$flash = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Ungültige Anfrage.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'upload') {
            $rateKey = 'rl_upload_print_' . getClientIp();
            if (!rateLimitCheck($pdo, $rateKey, (int) $cfg['rate_limit_max'], (int) $cfg['rate_limit_window_seconds'])) {
                $error = 'Zu viele Anfragen, bitte kurz warten.';
            } else {
                [$ok, $message] = uploadPrintStoreItem($_FILES['upload_file'] ?? []);
                if ($ok) {
                    $flash = (string) $message;
                } else {
                    $error = (string) $message;
                }
            }
        } elseif ($action === 'delete') {
            $id = trim((string) ($_POST['id'] ?? ''));
            if (isset($_SESSION['upload_print_items'][$id]) && is_array($_SESSION['upload_print_items'][$id])) {
                $path = resolveUploadPrintFile($id, $_SESSION['upload_print_items'][$id]);
                if ($path !== null) {
                    @unlink($path);
                }
                unset($_SESSION['upload_print_items'][$id]);
                $flash = 'Bild gelöscht.';
            }
        } elseif ($action === 'print') {
            $id = trim((string) ($_POST['id'] ?? ''));
            $item = $_SESSION['upload_print_items'][$id] ?? null;
            if (!is_array($item)) {
                $error = 'Bild nicht gefunden.';
            } else {
                $configuredApiKey = trim((string) ($cfg['print_api_key'] ?? ''));
                $apiKeyConfigured = $configuredApiKey !== '' && $configuredApiKey !== 'CHANGE_ME_PRINT_API_KEY';
                $printConfigured = $apiKeyConfigured || getConfiguredPrinterName($pdo) !== '';
                if (!$printConfigured) {
                    $error = 'Drucker ist nicht konfiguriert.';
                } else {
                    $rateKey = 'rl_upload_print_job_' . getClientIp();
                    if (!rateLimitCheck($pdo, $rateKey, (int) $cfg['rate_limit_max'], (int) $cfg['rate_limit_window_seconds'])) {
                        $error = 'Zu viele Anfragen, bitte kurz warten.';
                    } else {
                        $openCountStmt = $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status IN ('queued','sending','spooled','needs_attention','paused')");
                        $openCount = (int) $openCountStmt->fetchColumn();
                        if ($openCount > 0) {
                            $error = 'Drucker ist gerade beschäftigt. Bitte warten, bis der aktuelle Job fertig ist.';
                        } else {
                            $sourcePath = resolveUploadPrintFile($id, $item);
                            if ($sourcePath === null) {
                                $error = 'Upload-Datei fehlt.';
                            } else {
                                $createdTs = nowTs();
                                $insert = $pdo->prepare('INSERT INTO print_jobs(photo_id, created_ts, status, error, last_error, attempts, updated_at) VALUES(:photoId, :createdTs, :status, :error, :lastError, :attempts, :updatedAt)');
                                $insert->execute([
                                    ':photoId' => 'upload:' . $id,
                                    ':createdTs' => $createdTs,
                                    ':status' => 'queued',
                                    ':error' => null,
                                    ':lastError' => null,
                                    ':attempts' => 0,
                                    ':updatedAt' => $createdTs,
                                ]);
                                $jobId = (int) $pdo->lastInsertId();

                                ensureDir(pathPrintfiles());
                                $ext = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
                                if ($ext !== 'jpg' && $ext !== 'jpeg' && $ext !== 'png') {
                                    $ext = 'jpg';
                                }
                                $printfile = pathPrintfiles() . '/' . $jobId . '.' . $ext;
                                if (!@copy($sourcePath, $printfile)) {
                                    $fail = $pdo->prepare('UPDATE print_jobs SET status = :status, last_error = :error, error = :error, last_error_at = :errorAt, updated_at = :updatedAt WHERE id = :id');
                                    $fail->execute([
                                        ':status' => 'failed_hard',
                                        ':error' => 'RENDER_FAILED',
                                        ':errorAt' => nowTs(),
                                        ':updatedAt' => nowTs(),
                                        ':id' => $jobId,
                                    ]);
                                    $error = 'Druckdatei konnte nicht erzeugt werden.';
                                } else {
                                    $update = $pdo->prepare('UPDATE print_jobs SET printfile_path = :printfilePath, updated_at = :updatedAt WHERE id = :id');
                                    $update->execute([
                                        ':printfilePath' => $printfile,
                                        ':updatedAt' => nowTs(),
                                        ':id' => $jobId,
                                    ]);
                                    $flash = 'Druckjob wurde angelegt.';
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

$items = $_SESSION['upload_print_items'];
usort($items, static function (array $a, array $b): int {
    return ((int) ($b['created_ts'] ?? 0)) <=> ((int) ($a['created_ts'] ?? 0));
});

ob_start();
?>
<div class="panel">
    <h2>Eigenes Bild drucken</h2>
    <p class="muted">Nur für diese aktuelle Session sichtbar. Nicht in der Galerie.</p>
    <p class="muted">Beim Drucker steht ein Sparschwein. Das Brautpaar freut sich über eine kleine Spende.</p>

    <?php if ($flash !== ''): ?>
        <p><strong><?= mobileEsc($flash) ?></strong></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p><strong><?= mobileEsc($error) ?></strong></p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="upload-print-form">
        <input type="hidden" name="csrf_token" value="<?= mobileEsc($csrfToken) ?>">
        <input type="hidden" name="action" value="upload">
        <input type="file" name="upload_file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
        <button type="submit">Bild hochladen</button>
    </form>
</div>

<?php if ($items === []): ?>
    <div class="empty-state">
        <p>Noch kein eigenes Bild hochgeladen.</p>
    </div>
<?php else: ?>
    <section class="grid">
        <?php foreach ($items as $item): ?>
            <?php $id = (string) ($item['id'] ?? ''); ?>
            <article class="tile">
                <a class="tile-link" href="/mobile/upload_image.php?id=<?= urlencode($id) ?>" target="_blank" rel="noopener noreferrer">
                    <img src="/mobile/upload_image.php?id=<?= urlencode($id) ?>" alt="Upload" loading="lazy" decoding="async" fetchpriority="low">
                    <time><?= date('d.m. H:i', (int) ($item['created_ts'] ?? nowTs())) ?></time>
                </a>
                <div class="actions" style="padding:.35rem .35rem .45rem;">
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= mobileEsc($csrfToken) ?>">
                        <input type="hidden" name="action" value="print">
                        <input type="hidden" name="id" value="<?= mobileEsc($id) ?>">
                        <button type="submit">Drucken</button>
                    </form>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= mobileEsc($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= mobileEsc($id) ?>">
                        <button type="submit" class="button-danger">Löschen</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

mobileRenderLayout([
    'title' => 'Eigenes Bild drucken',
    'status_line' => 'Eigenes Bild drucken',
    'active_view' => 'favs',
    'content_html' => $content,
]);

