<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();

requireAdminSilently();
$csrfToken = getCsrfToken();
$pdo = pdo();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'retry_job') {
        $jobId = (int) ($_POST['job_id'] ?? 0);
        if ($jobId > 0) {
            $stmt = $pdo->prepare(
                "UPDATE print_jobs SET status = 'queued', error = NULL, last_error = NULL, last_error_at = NULL, "
                . "next_retry_at = NULL, spool_job_id = NULL, document_name = NULL, updated_at = :updatedAt WHERE id = :id"
            );
            $stmt->execute([
                ':id' => $jobId,
                ':updatedAt' => nowTs(),
            ]);
            adminActionLog('retry_job', ['id' => $jobId]);
        }
        header('Location: /admin/?tab=jobs', true, 302);
        exit;
    }

    if ($action === 'cancel_job') {
        $jobId = (int) ($_POST['job_id'] ?? 0);
        if ($jobId > 0) {
            $stmt = $pdo->prepare(
                "UPDATE print_jobs SET status = 'canceled', error = NULL, last_error = 'MANUAL_CANCELED', "
                . "last_error_at = :updatedAt, next_retry_at = NULL, spool_job_id = NULL, document_name = NULL, updated_at = :updatedAt WHERE id = :id"
            );
            $stmt->execute([
                ':id' => $jobId,
                ':updatedAt' => nowTs(),
            ]);
            adminActionLog('cancel_job', ['id' => $jobId]);
        }
        header('Location: /admin/?tab=jobs', true, 302);
        exit;
    }

    if ($action === 'delete_job') {
        $jobId = (int) ($_POST['job_id'] ?? 0);
        if ($jobId > 0) {
            $q = $pdo->prepare('SELECT printfile_path FROM print_jobs WHERE id = :id');
            $q->execute([':id' => $jobId]);
            $row = $q->fetch();
            if (is_array($row)) {
                $printfilePath = trim((string) ($row['printfile_path'] ?? ''));
                if ($printfilePath !== '') {
                    $base = realpath(app_paths()['data'] . '/printfiles');
                    $resolved = realpath($printfilePath);
                    if ($base !== false && $resolved !== false && str_starts_with($resolved, $base . DIRECTORY_SEPARATOR) && is_file($resolved)) {
                        @unlink($resolved);
                    }
                }
            }

            $del = $pdo->prepare('DELETE FROM print_jobs WHERE id = :id');
            $del->execute([':id' => $jobId]);
            adminActionLog('delete_job', ['id' => $jobId]);
        }
        header('Location: /admin/?tab=jobs', true, 302);
        exit;
    }

    if ($action === 'complete_order') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId > 0) {
            $stmt = $pdo->prepare("UPDATE orders SET status = 'done' WHERE id = :id");
            $stmt->execute([':id' => $orderId]);
            adminActionLog('complete_order', ['id' => $orderId]);
        }
        header('Location: /admin/?tab=orders', true, 302);
        exit;
    }
}

$tab = (string) ($_GET['tab'] ?? 'jobs');
if (!in_array($tab, ['jobs', 'orders', 'photos', 'printer'], true)) {
    $tab = 'jobs';
}

$jobs = $pdo->query('SELECT j.id, j.photo_id, j.status, j.error, j.last_error, j.created_ts, j.printfile_path, p.id AS photo_exists FROM print_jobs j LEFT JOIN photos p ON p.id = j.photo_id ORDER BY j.id DESC LIMIT 120')->fetchAll();
$orders = $pdo->query('SELECT id, created_at, name, email, photo_count, shipping_enabled, price_cents, status, zip_path FROM orders ORDER BY id DESC LIMIT 120')->fetchAll();
$photos = $pdo->query(
    'SELECT p.id, p.token, p.ts, COALESCE(m.view_count, 0) AS view_count, COALESCE(m.like_count, 0) AS like_count '
    . 'FROM photos p '
    . 'LEFT JOIN photo_metrics m ON m.photo_id = p.id '
    . 'WHERE p.deleted = 0 ORDER BY p.ts DESC LIMIT 240'
)->fetchAll();
$topViewedPhotos = $pdo->query(
    'SELECT p.id, p.token, p.ts, COALESCE(m.view_count, 0) AS view_count, COALESCE(m.like_count, 0) AS like_count '
    . 'FROM photos p '
    . 'LEFT JOIN photo_metrics m ON m.photo_id = p.id '
    . 'WHERE p.deleted = 0 ORDER BY COALESCE(m.view_count, 0) DESC, p.ts DESC LIMIT 10'
)->fetchAll();
$topLikedPhotos = $pdo->query(
    'SELECT p.id, p.token, p.ts, COALESCE(m.view_count, 0) AS view_count, COALESCE(m.like_count, 0) AS like_count '
    . 'FROM photos p '
    . 'LEFT JOIN photo_metrics m ON m.photo_id = p.id '
    . 'WHERE p.deleted = 0 ORDER BY COALESCE(m.like_count, 0) DESC, p.ts DESC LIMIT 10'
)->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin</title>
    <link rel="stylesheet" href="/admin/style.css">
</head>
<body>
<main class="container">
    <header class="admin-head">
        <h1>Admin</h1>
        <div class="camera-preview-wrap" aria-label="Kamera-Vorschau">
            <img id="camera-preview" src="/admin/camera_preview.php" alt="Kamera Vorschau" width="80" height="80">
        </div>
    </header>
    <nav class="tabs">
        <a class="<?= $tab === 'jobs' ? 'is-active' : '' ?>" href="/admin/?tab=jobs">Druckauftraege</a>
        <a class="<?= $tab === 'orders' ? 'is-active' : '' ?>" href="/admin/?tab=orders">Bestellungen</a>
        <a class="<?= $tab === 'photos' ? 'is-active' : '' ?>" href="/admin/?tab=photos">Bilder</a>
        <a class="<?= $tab === 'printer' ? 'is-active' : '' ?>" href="/admin/?tab=printer">Drucker</a>
    </nav>

    <?php if ($tab === 'jobs'): ?>
        <section class="panel">
            <table>
                <thead><tr><th>ID</th><th>Status</th><th>Zeit</th><th>Foto</th><th>Fehler</th><th>Aktion</th></tr></thead>
                <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td>#<?= (int) $job['id'] ?></td>
                        <td><?= htmlspecialchars((string) $job['status'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= date('Y-m-d H:i:s', (int) $job['created_ts']) ?></td>
                        <td><?= $job['photo_exists'] ? htmlspecialchars((string) $job['photo_id'], ENT_QUOTES, 'UTF-8') : 'Foto geloescht' ?></td>
                        <td><?= htmlspecialchars((string) (($job['last_error'] ?? '') !== '' ? $job['last_error'] : ($job['error'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php
                            $status = (string) $job['status'];
                            $hasPrintfilePath = trim((string) ($job['printfile_path'] ?? '')) !== '';
                            $canRetry = in_array($status, ['error', 'failed_hard', 'needs_attention', 'paused', 'canceled'], true) && $hasPrintfilePath;
                            $canCancel = in_array($status, ['queued', 'sending', 'spooled', 'needs_attention', 'paused', 'error', 'failed_hard'], true);
                            $canDelete = in_array($status, ['canceled', 'failed_hard', 'error', 'done'], true);
                            ?>
                            <?php if ($canRetry): ?>
                                <form method="post" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="retry_job">
                                    <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                    <button type="submit">Retry</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canCancel): ?>
                                <form method="post" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="cancel_job">
                                    <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                    <button type="submit" class="danger">Abbrechen</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form method="post" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="delete_job">
                                    <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                    <button type="submit" class="danger">Löschen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php elseif ($tab === 'orders'): ?>
        <section class="panel">
            <table>
                <thead><tr><th>ID</th><th>Zeit</th><th>Name</th><th>E-Mail</th><th>Count</th><th>Versand</th><th>Preis</th><th>ZIP</th><th>Status</th><th>Aktion</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td>#<?= (int) $order['id'] ?></td>
                        <td><?= htmlspecialchars((string) ($order['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($order['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($order['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int) ($order['photo_count'] ?? 0) ?></td>
                        <td><?= ((int) ($order['shipping_enabled'] ?? 0)) === 1 ? 'ja' : 'nein' ?></td>
                        <td><?= number_format(((int) ($order['price_cents'] ?? 0)) / 100, 2, ',', '.') ?> EUR</td>
                        <td>
                            <?php if (trim((string) ($order['zip_path'] ?? '')) !== ''): ?>
                                <a href="/admin/download_order_zip.php?id=<?= (int) $order['id'] ?>">ZIP</a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string) ($order['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ((string) ($order['status'] ?? '') !== 'done'): ?>
                                <form method="post" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="complete_order">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <button type="submit">Erledigen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php elseif ($tab === 'photos'): ?>
        <section class="stats-grid">
            <article class="panel">
                <h2>Top 10 geklickt</h2>
                <table>
                    <thead><tr><th>Bild</th><th>Klicks</th><th>Likes</th></tr></thead>
                    <tbody>
                    <?php foreach ($topViewedPhotos as $photo): ?>
                        <tr>
                            <td>
                                <a href="/mobile/photo.php?id=<?= urlencode((string) $photo['id']) ?>&amp;view=all&amp;return=<?= urlencode('/admin/?tab=photos') ?>">
                                    <?= htmlspecialchars((string) $photo['id'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </td>
                            <td><?= (int) ($photo['view_count'] ?? 0) ?></td>
                            <td><?= (int) ($photo['like_count'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </article>
            <article class="panel">
                <h2>Top 10 gelikt</h2>
                <table>
                    <thead><tr><th>Bild</th><th>Likes</th><th>Klicks</th></tr></thead>
                    <tbody>
                    <?php foreach ($topLikedPhotos as $photo): ?>
                        <tr>
                            <td>
                                <a href="/mobile/photo.php?id=<?= urlencode((string) $photo['id']) ?>&amp;view=all&amp;return=<?= urlencode('/admin/?tab=photos') ?>">
                                    <?= htmlspecialchars((string) $photo['id'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </td>
                            <td><?= (int) ($photo['like_count'] ?? 0) ?></td>
                            <td><?= (int) ($photo['view_count'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </article>
        </section>
        <section class="panel">
            <div class="grid">
                <?php foreach ($photos as $photo): ?>
                    <article class="tile" data-photo-id="<?= htmlspecialchars((string) $photo['id'], ENT_QUOTES, 'UTF-8') ?>">
                        <img src="/mobile/image.php?t=<?= urlencode((string) $photo['token']) ?>&amp;type=thumb" alt="Foto">
                        <p><?= date('d.m. H:i', (int) $photo['ts']) ?></p>
                        <p><strong>Klicks:</strong> <?= (int) ($photo['view_count'] ?? 0) ?> <strong>Likes:</strong> <?= (int) ($photo['like_count'] ?? 0) ?></p>
                        <p><button class="danger" type="button" data-delete-photo data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">Loeschen</button></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php else: ?>
        <section class="panel">
            <div class="inline">
                <label for="printer-select">Drucker</label>
                <select id="printer-select"></select>
                <button type="button" id="refresh-printers">Neu laden</button>
                <button type="button" id="save-printer" data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">Speichern</button>
            </div>
            <div class="inline" style="margin-top:.6rem;">
                <label for="cp1500-ip">CP1500 IP</label>
                <input id="cp1500-ip" type="text" inputmode="decimal" placeholder="z. B. 192.168.8.50">
                <button type="button" id="connect-cp1500" data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">CP1500 koppeln</button>
            </div>
            <p id="printer-status" class="muted" style="margin-top:.6rem;">Nur Erkennung/Verbindungstest. Kein Testdruck erforderlich.</p>
        </section>
    <?php endif; ?>
</main>

<script>
(() => {
  function parseJsonResponse(response) {
    if (!response.ok) throw new Error('HTTP ' + response.status);
    const contentType = (response.headers.get('content-type') || '').toLowerCase();
    if (!contentType.includes('application/json')) throw new Error('INVALID_CONTENT_TYPE');
    return response.json();
  }

  const delButtons = document.querySelectorAll('[data-delete-photo]');
  delButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const tile = btn.closest('[data-photo-id]');
      if (!tile) return;
      const id = tile.getAttribute('data-photo-id');
      const csrfToken = btn.getAttribute('data-csrf-token') || '';
      const body = new URLSearchParams({ id, csrf_token: csrfToken });
      fetch('/admin/api_delete_photo.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body
      }).then(parseJsonResponse).then((res) => {
        if (res && res.ok) tile.remove();
      }).catch(() => {});
    });
  });

  const select = document.getElementById('printer-select');
  const save = document.getElementById('save-printer');
  const refresh = document.getElementById('refresh-printers');
  const connect = document.getElementById('connect-cp1500');
  const ipInput = document.getElementById('cp1500-ip');
  const status = document.getElementById('printer-status');
  const camPreview = document.getElementById('camera-preview');
  if (camPreview) {
    const reloadPreview = () => {
      camPreview.src = '/admin/camera_preview.php?t=' + Date.now();
    };
    setInterval(reloadPreview, 4000);
  }
  if (!select || !save || !refresh || !connect || !ipInput || !status) return;

  function setStatus(text) {
    status.textContent = text;
  }

  function fillSelect(res) {
    select.innerHTML = '';
    const current = res.current || '';
    const opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = '(Auto)';
    select.appendChild(opt0);
    (res.printers || []).forEach((name) => {
      const opt = document.createElement('option');
      opt.value = name;
      opt.textContent = name;
      select.appendChild(opt);
    });
    select.value = current;
  }

  function renderDiag(res) {
    const cp = res.cp1500 || {};
    const names = Array.isArray(cp.detectedNames) && cp.detectedNames.length > 0
      ? cp.detectedNames.join(', ')
      : 'kein CP1500 erkannt';
    const spool = cp.spoolerRunning === true ? 'Spooler: läuft' : (cp.spoolerRunning === false ? 'Spooler: gestoppt' : 'Spooler: unbekannt');
    setStatus(`${spool} | CP1500: ${names}`);
  }

  function loadPrinters() {
    fetch('/admin/api_printers.php')
      .then(parseJsonResponse)
      .then((res) => {
        if (!res || !res.ok) {
          setStatus('Druckerstatus konnte nicht geladen werden.');
          return;
        }
        fillSelect(res);
        renderDiag(res);
      })
      .catch(() => setStatus('Druckerstatus konnte nicht geladen werden.'));
  }

  refresh.addEventListener('click', loadPrinters);

  save.addEventListener('click', () => {
    const csrfToken = save.getAttribute('data-csrf-token') || '';
    const body = new URLSearchParams({ name: select.value || '', csrf_token: csrfToken, action: 'save' });
    fetch('/admin/api_printers.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body
    }).then(parseJsonResponse)
      .then((res) => {
        if (res && res.ok) {
          setStatus('Drucker gespeichert.');
        }
      })
      .catch(() => setStatus('Drucker konnte nicht gespeichert werden.'));
  });

  connect.addEventListener('click', () => {
    const ip = (ipInput.value || '').trim();
    const csrfToken = connect.getAttribute('data-csrf-token') || '';
    if (!ip) {
      setStatus('Bitte zuerst die CP1500-IP eingeben.');
      return;
    }

    setStatus('CP1500-Kopplung läuft...');
    const body = new URLSearchParams({ action: 'auto_connect_cp1500', ip, csrf_token: csrfToken });
    fetch('/admin/api_printers.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body
    }).then(parseJsonResponse)
      .then((res) => {
        if (!res || !res.result) {
          setStatus('CP1500-Kopplung fehlgeschlagen.');
          return;
        }
        fillSelect(res);
        const ok = res.result.ok === true;
        const name = res.result.installedName || 'unbekannt';
        const err = res.result.error || '';
        if (ok) {
          setStatus(`CP1500 erkannt: ${name}`);
        } else {
          setStatus(`CP1500 nicht gekoppelt (${err || 'unbekannter Fehler'}).`);
        }
      })
      .catch(() => setStatus('CP1500-Kopplung fehlgeschlagen.'));
  });

  loadPrinters();
})();
</script>
</body>
</html>

