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

function uploadPrintConfigInt(array $cfg, string $key, int $default, int $min, int $max): int
{
    $value = (int) ($cfg[$key] ?? $default);
    return max($min, min($max, $value));
}

function uploadPrintIsValidId(string $id): bool
{
    return (bool) preg_match('/^[a-f0-9]{24}$/', $id);
}

function uploadPrintIniSizeToBytes(string $value): int
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return 0;
    }

    $unit = substr($normalized, -1);
    $number = (int) $normalized;
    if ($unit === 'g') {
        return $number * 1024 * 1024 * 1024;
    }
    if ($unit === 'm') {
        return $number * 1024 * 1024;
    }
    if ($unit === 'k') {
        return $number * 1024;
    }

    return $number;
}

function uploadPrintPhpRequestTooLarge(): bool
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength <= 0) {
        return false;
    }

    $postMaxBytes = uploadPrintIniSizeToBytes((string) ini_get('post_max_size'));
    if ($postMaxBytes <= 0) {
        return false;
    }

    return $contentLength > $postMaxBytes && $_POST === [] && $_FILES === [];
}

function uploadPrintSessionDir(): string
{
    $sessionId = preg_replace('/[^a-zA-Z0-9]/', '', session_id());
    if (!is_string($sessionId) || $sessionId === '') {
        $sessionId = 'anon';
    }
    return pathData() . '/uploads/session_' . $sessionId;
}

function resolveUploadPrintFile(array $item): ?string
{
    $dir = uploadPrintSessionDir();
    $filename = basename((string) ($item['filename'] ?? ''));
    if ($filename === '') {
        return null;
    }

    $path = resolvePathInDirectory($dir, $filename);
    if ($path === null) {
        return null;
    }

    return $path;
}

function uploadPrintPruneSessionItems(array $cfg): void
{
    $items = $_SESSION['upload_print_items'] ?? [];
    if (!is_array($items)) {
        $_SESSION['upload_print_items'] = [];
        return;
    }

    $maxFiles = uploadPrintConfigInt($cfg, 'upload_print_max_files', 12, 1, 200);
    $maxTotalMb = uploadPrintConfigInt($cfg, 'upload_print_max_total_mb', 80, 10, 1024);
    $maxAgeHours = uploadPrintConfigInt($cfg, 'upload_print_max_age_hours', 12, 1, 168);
    $maxTotalBytes = $maxTotalMb * 1024 * 1024;
    $maxAgeSeconds = $maxAgeHours * 3600;
    $now = nowTs();

    $validRows = [];
    $deletePaths = [];

    foreach ($items as $id => $item) {
        if (!is_string($id) || !uploadPrintIsValidId($id) || !is_array($item)) {
            continue;
        }

        $createdTs = (int) ($item['created_ts'] ?? 0);
        if ($createdTs <= 0) {
            $createdTs = $now;
        }
        if (($createdTs + $maxAgeSeconds) < $now) {
            $stalePath = resolveUploadPrintFile($item);
            if ($stalePath !== null) {
                $deletePaths[$stalePath] = true;
            }
            continue;
        }

        $path = resolveUploadPrintFile($item);
        if ($path === null) {
            continue;
        }

        $size = filesize($path);
        $sizeBytes = is_int($size) && $size > 0 ? $size : 0;
        if ($sizeBytes <= 0) {
            $deletePaths[$path] = true;
            continue;
        }

        $item['id'] = $id;
        $item['filename'] = basename((string) ($item['filename'] ?? ''));
        $item['mime'] = strtolower(trim((string) ($item['mime'] ?? '')));
        $item['created_ts'] = $createdTs;
        $item['file_size'] = $sizeBytes;
        $validRows[] = $item;
    }

    usort($validRows, static function (array $a, array $b): int {
        return ((int) ($b['created_ts'] ?? 0)) <=> ((int) ($a['created_ts'] ?? 0));
    });

    $kept = [];
    $totalBytes = 0;
    foreach ($validRows as $row) {
        $id = (string) ($row['id'] ?? '');
        $sizeBytes = (int) ($row['file_size'] ?? 0);
        $path = resolveUploadPrintFile($row);
        if (!uploadPrintIsValidId($id) || $sizeBytes <= 0 || $path === null) {
            continue;
        }

        if (count($kept) >= $maxFiles || ($totalBytes + $sizeBytes) > $maxTotalBytes) {
            $deletePaths[$path] = true;
            continue;
        }

        $totalBytes += $sizeBytes;
        $kept[$id] = $row;
    }

    foreach (array_keys($deletePaths) as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    $_SESSION['upload_print_items'] = $kept;
}

function uploadPrintStoreItem(array $file, array $cfg): array
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

    $maxFiles = uploadPrintConfigInt($cfg, 'upload_print_max_files', 12, 1, 200);
    if (count($_SESSION['upload_print_items']) >= $maxFiles) {
        return [false, 'Maximale Anzahl an Uploads in dieser Session erreicht.'];
    }

    $maxTotalMb = uploadPrintConfigInt($cfg, 'upload_print_max_total_mb', 80, 10, 1024);
    $maxTotalBytes = $maxTotalMb * 1024 * 1024;
    $usedBytes = 0;
    foreach ($_SESSION['upload_print_items'] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $path = resolveUploadPrintFile($item);
        if ($path === null) {
            continue;
        }
        $itemSize = filesize($path);
        if (is_int($itemSize) && $itemSize > 0) {
            $usedBytes += $itemSize;
        }
    }
    if (($usedBytes + $size) > $maxTotalBytes) {
        return [false, 'Upload-Limit für diese Session erreicht. Bitte erst Bilder löschen.'];
    }

    $imageInfo = @getimagesize($tmp);
    if (!is_array($imageInfo)) {
        return [false, 'Datei ist kein gültiges Bild.'];
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower(trim((string) ($finfo->file($tmp) ?: '')));
    }
    if ($mime === '') {
        $mime = strtolower(trim((string) ($imageInfo['mime'] ?? '')));
    }

    $ext = '';
    if ($mime === 'image/jpeg') {
        $ext = 'jpg';
    } elseif ($mime === 'image/png') {
        $ext = 'png';
    } else {
        return [false, 'Nur JPG oder PNG ist erlaubt.'];
    }

    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    if ($width <= 0 || $height <= 0) {
        return [false, 'Bildabmessungen sind ungültig.'];
    }
    $maxDimension = uploadPrintConfigInt($cfg, 'upload_print_max_dimension', 12000, 512, 30000);
    if ($width > $maxDimension || $height > $maxDimension) {
        return [false, 'Bild ist zu groß (Pixelmaß).'];
    }

    $id = bin2hex(random_bytes(12));
    ensureDir(pathData() . '/uploads');
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
        'file_size' => $size,
        'width' => $width,
        'height' => $height,
        'orig_name' => textSubstr((string) ($file['name'] ?? ''), 0, 120),
    ];

    return [true, 'Bild gespeichert.'];
}

$flash = '';
$error = '';
uploadPrintPruneSessionItems($cfg);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Ungültige Anfrage.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'upload') {
            if (uploadPrintPhpRequestTooLarge()) {
                $limitMb = max(1, (int) floor(uploadPrintIniSizeToBytes((string) ini_get('post_max_size')) / (1024 * 1024)));
                $error = 'Datei ist zu groß für den aktuellen Server-Upload (maximal ' . $limitMb . ' MB).';
            } else {
                $rateKey = 'rl_upload_print_' . getClientIp();
                if (!rateLimitCheck($pdo, $rateKey, (int) $cfg['rate_limit_max'], (int) $cfg['rate_limit_window_seconds'])) {
                    $error = 'Zu viele Anfragen, bitte kurz warten.';
                } else {
                    [$ok, $message] = uploadPrintStoreItem($_FILES['upload_file'] ?? [], $cfg);
                    if ($ok) {
                        $flash = (string) $message;
                    } else {
                        $error = (string) $message;
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = trim((string) ($_POST['id'] ?? ''));
            if (!uploadPrintIsValidId($id)) {
                $error = 'Ungültige Bild-ID.';
            } elseif (isset($_SESSION['upload_print_items'][$id]) && is_array($_SESSION['upload_print_items'][$id])) {
                $path = resolveUploadPrintFile($_SESSION['upload_print_items'][$id]);
                if ($path !== null) {
                    @unlink($path);
                }
                unset($_SESSION['upload_print_items'][$id]);
                $flash = 'Bild gelöscht.';
            }
        } elseif ($action === 'print') {
            $id = trim((string) ($_POST['id'] ?? ''));
            if (!uploadPrintIsValidId($id)) {
                $error = 'Ungültige Bild-ID.';
            }
            $item = $_SESSION['upload_print_items'][$id] ?? null;
            if ($error === '' && !is_array($item)) {
                $error = 'Bild nicht gefunden.';
            } elseif ($error === '') {
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
                            $sourcePath = resolveUploadPrintFile($item);
                            if ($sourcePath === null) {
                                $error = 'Upload-Datei fehlt.';
                            } else {
                                try {
                                    $pdo->beginTransaction();
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
                                    $pdo->commit();
                                } catch (Throwable $e) {
                                    if ($pdo->inTransaction()) {
                                        $pdo->rollBack();
                                    }
                                    write_log(pathLogs() . '/mobile.log', 'upload_print_exception ' . $e->getMessage());
                                    $error = 'Druckjob konnte nicht angelegt werden.';
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
        <button type="submit" class="upload-submit">Bild hochladen</button>
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
    'active_view' => 'upload',
    'content_html' => $content,
]);
