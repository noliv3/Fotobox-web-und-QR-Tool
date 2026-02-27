<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();

$pdo = pdo();
$cfg = config();
$windowMinutes = (int) $cfg['gallery_window_minutes'];
$tokenTime = $_GET['time'] ?? '';
$where = 'deleted = 0';
$params = [];

if (is_string($tokenTime) && $tokenTime !== '') {
    if (!validateTimeHHMM($tokenTime)) {
        http_response_code(400);
        echo 'Ungültiges Zeitformat (HH:MM erwartet).';
        exit;
    }

    $targetTs = parseTimeToTsToday($tokenTime, (string) $cfg['timezone']);
    $where .= ' AND ts BETWEEN :fromTs AND :toTs';
    $params[':fromTs'] = $targetTs - 600;
    $params[':toTs'] = $targetTs + 600;
} else {
    $where .= ' AND ts >= :minTs';
    $params[':minTs'] = nowTs() - ($windowMinutes * 60);
}

$stmt = $pdo->prepare("SELECT token, ts FROM photos WHERE {$where} ORDER BY ts DESC LIMIT 240");
$stmt->execute($params);
$photos = $stmt->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mobile Galerie</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
    <h1>Galerie</h1>
    <p class="nav-links"><a href="all.php">Alle Fotos</a> <a href="order.php">Meine Bestellung</a></p>

    <form method="get" class="panel form-row">
        <label for="time">Uhrzeit (HH:MM)</label>
        <input type="time" id="time" name="time" value="<?= htmlspecialchars((string) $tokenTime, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Anzeigen</button>
    </form>

    <section class="grid">
        <?php foreach ($photos as $photo): ?>
            <a class="card" href="photo.php?t=<?= urlencode((string) $photo['token']) ?>">
                <img src="image.php?t=<?= urlencode((string) $photo['token']) ?>&amp;type=thumb" alt="Foto-Thumbnail" loading="lazy">
                <span><?= date('H:i', (int) $photo['ts']) ?></span>
            </a>
        <?php endforeach; ?>
    </section>
</main>
</body>
</html>
