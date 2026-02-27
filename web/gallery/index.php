<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

session_start();
$cfg = app_config();

if (isset($_POST['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

if (!($_SESSION['admin_auth'] ?? false)) {
    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $password = (string) ($_POST['password'] ?? '');
        $hash = (string) $cfg['admin_password_hash_placeholder'];
        if ($hash !== '' && str_starts_with($hash, '$2') && password_verify($password, $hash)) {
            $_SESSION['admin_auth'] = true;
            header('Location: index.php');
            exit;
        }
        $error = 'Login fehlgeschlagen.';
    }

    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Fotobox Monitor Login</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
    <main class="container">
        <h1>Admin Login</h1>
        <?php if ($error !== ''): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post">
            <label for="password">Passwort</label>
            <input id="password" name="password" type="password" required>
            <button type="submit">Einloggen</button>
        </form>
    </main>
    </body>
    </html>
    <?php
    exit;
}

$pdo = app_pdo();

$counts = [
    'photos_total' => (int) $pdo->query('SELECT COUNT(*) FROM photos WHERE deleted = 0')->fetchColumn(),
    'photos_today' => (int) $pdo->query('SELECT COUNT(*) FROM photos WHERE deleted = 0 AND ts >= strftime("%s", date("now", "localtime", "start of day"))')->fetchColumn(),
    'pending' => (int) $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status = 'pending'")->fetchColumn(),
    'printing' => (int) $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status = 'printing'")->fetchColumn(),
    'done' => (int) $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status = 'done'")->fetchColumn(),
    'error' => (int) $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status = 'error'")->fetchColumn(),
];

$latestPhotos = $pdo->query('SELECT id, ts, token FROM photos WHERE deleted = 0 ORDER BY ts DESC LIMIT 10')->fetchAll();
$latestJobs = $pdo->query('SELECT id, photo_id, status, error, created_ts FROM print_jobs ORDER BY id DESC LIMIT 10')->fetchAll();
$latestErrors = $pdo->query("SELECT id, error, created_ts FROM print_jobs WHERE error IS NOT NULL AND error != '' ORDER BY id DESC LIMIT 10")->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fotobox Monitor</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
    <h1>Fotobox Monitor</h1>
    <form method="post"><button type="submit" name="logout" value="1">Logout</button></form>

    <section>
        <h2>Status</h2>
        <ul>
            <li>Fotos gesamt: <?= $counts['photos_total'] ?></li>
            <li>Fotos heute: <?= $counts['photos_today'] ?></li>
            <li>Jobs pending: <?= $counts['pending'] ?></li>
            <li>Jobs printing: <?= $counts['printing'] ?></li>
            <li>Jobs done: <?= $counts['done'] ?></li>
            <li>Jobs error: <?= $counts['error'] ?></li>
        </ul>
    </section>

    <section>
        <h2>Letzte Fotos</h2>
        <ul>
            <?php foreach ($latestPhotos as $photo): ?>
                <li><?= htmlspecialchars($photo['id'], ENT_QUOTES, 'UTF-8') ?> · <?= date('Y-m-d H:i:s', (int) $photo['ts']) ?> ·
                    <a href="../mobile/photo.php?t=<?= urlencode($photo['token']) ?>">öffnen</a></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section>
        <h2>Letzte Jobs</h2>
        <ul>
            <?php foreach ($latestJobs as $job): ?>
                <li>#<?= (int) $job['id'] ?> · <?= htmlspecialchars($job['photo_id'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($job['status'], ENT_QUOTES, 'UTF-8') ?> · <?= date('Y-m-d H:i:s', (int) $job['created_ts']) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section>
        <h2>Letzte Errors</h2>
        <ul>
            <?php foreach ($latestErrors as $error): ?>
                <li>#<?= (int) $error['id'] ?> · <?= htmlspecialchars((string) $error['error'], ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
</main>
</body>
</html>
