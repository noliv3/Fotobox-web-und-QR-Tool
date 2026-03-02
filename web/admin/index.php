<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();

$code = requireAdminSilently();
$pdo = pdo();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'retry_job') {
        $jobId = (int) ($_POST['job_id'] ?? 0);
        if ($jobId > 0) {
            $stmt = $pdo->prepare("UPDATE print_jobs SET status = 'pending', error = NULL WHERE id = :id");
            $stmt->execute([':id' => $jobId]);
            adminActionLog('retry_job', ['id' => $jobId]);
        }
        header('Location: /admin/?code=' . urlencode($code) . '&tab=jobs', true, 302);
        exit;
    }
}

$tab = (string) ($_GET['tab'] ?? 'jobs');
if (!in_array($tab, ['jobs', 'orders', 'photos', 'printer'], true)) {
    $tab = 'jobs';
}

$jobs = $pdo->query('SELECT j.id, j.photo_id, j.status, j.error, j.created_ts, p.id AS photo_exists FROM print_jobs j LEFT JOIN photos p ON p.id = j.photo_id ORDER BY j.id DESC LIMIT 120')->fetchAll();
$orders = $pdo->query('SELECT id, created_at, name, count, shipping_enabled, price_total, status FROM orders ORDER BY id DESC LIMIT 120')->fetchAll();
$photos = $pdo->query('SELECT id, token, ts FROM photos WHERE deleted = 0 ORDER BY ts DESC LIMIT 240')->fetchAll();
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
    <h1>Admin</h1>
    <nav class="tabs">
        <a class="<?= $tab === 'jobs' ? 'is-active' : '' ?>" href="/admin/?code=<?= urlencode($code) ?>&amp;tab=jobs">Druckauftraege</a>
        <a class="<?= $tab === 'orders' ? 'is-active' : '' ?>" href="/admin/?code=<?= urlencode($code) ?>&amp;tab=orders">Bestellungen</a>
        <a class="<?= $tab === 'photos' ? 'is-active' : '' ?>" href="/admin/?code=<?= urlencode($code) ?>&amp;tab=photos">Bilder</a>
        <a class="<?= $tab === 'printer' ? 'is-active' : '' ?>" href="/admin/?code=<?= urlencode($code) ?>&amp;tab=printer">Drucker</a>
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
                        <td><?= htmlspecialchars((string) ($job['error'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ((string) $job['status'] === 'error'): ?>
                                <form method="post" class="inline">
                                    <input type="hidden" name="code" value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="retry_job">
                                    <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                    <button type="submit">Retry</button>
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
                <thead><tr><th>ID</th><th>Zeit</th><th>Name</th><th>Count</th><th>Versand</th><th>Preis</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td>#<?= (int) $order['id'] ?></td>
                        <td><?= htmlspecialchars((string) ($order['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($order['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int) ($order['count'] ?? 0) ?></td>
                        <td><?= ((int) ($order['shipping_enabled'] ?? 0)) === 1 ? 'ja' : 'nein' ?></td>
                        <td><?= number_format((float) ($order['price_total'] ?? 0), 2, ',', '.') ?> EUR</td>
                        <td><?= htmlspecialchars((string) ($order['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php elseif ($tab === 'photos'): ?>
        <section class="panel">
            <div class="grid">
                <?php foreach ($photos as $photo): ?>
                    <article class="tile" data-photo-id="<?= htmlspecialchars((string) $photo['id'], ENT_QUOTES, 'UTF-8') ?>">
                        <img src="/mobile/image.php?t=<?= urlencode((string) $photo['token']) ?>&amp;type=thumb" alt="Foto">
                        <p><?= date('d.m. H:i', (int) $photo['ts']) ?></p>
                        <p><button class="danger" type="button" data-delete-photo data-code="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">Loeschen</button></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php else: ?>
        <section class="panel">
            <div class="inline">
                <label for="printer-select">Drucker</label>
                <select id="printer-select"></select>
                <button type="button" id="save-printer" data-code="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">Speichern</button>
            </div>
            <p class="muted" id="printer-status"></p>
        </section>
    <?php endif; ?>
</main>

<script>
(() => {
  const delButtons = document.querySelectorAll('[data-delete-photo]');
  delButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const tile = btn.closest('[data-photo-id]');
      if (!tile) return;
      const id = tile.getAttribute('data-photo-id');
      const code = btn.getAttribute('data-code') || '';
      const body = new URLSearchParams({ id, code });
      fetch('/admin/api_delete_photo.php?code=' + encodeURIComponent(code), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body
      }).then((r) => r.json()).then((res) => {
        if (res && res.ok) tile.remove();
      });
    });
  });

  const select = document.getElementById('printer-select');
  const save = document.getElementById('save-printer');
  const status = document.getElementById('printer-status');
  if (!select || !save || !status) return;

  const code = save.getAttribute('data-code') || '';
  fetch('/admin/api_printers.php?code=' + encodeURIComponent(code))
    .then((r) => r.json())
    .then((res) => {
      if (!res || !res.ok) return;
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
    });

  save.addEventListener('click', () => {
    const body = new URLSearchParams({ code, name: select.value || '' });
    fetch('/admin/api_printers.php?code=' + encodeURIComponent(code), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body
    }).then((r) => r.json()).then((res) => {
      if (res && res.ok) status.textContent = 'Gespeichert';
    });
  });
})();
</script>
</body>
</html>
