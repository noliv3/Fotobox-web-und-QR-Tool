<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

$pdo = app_pdo();
$cfg = app_config();
$windowMinutes = (int) $cfg['gallery_window_minutes'];
$timeFilter = $_GET['time'] ?? '';
$params = [];
$where = 'deleted = 0';

if (is_string($timeFilter) && $timeFilter !== '') {
    if (!validate_hhmm($timeFilter)) {
        http_response_code(400);
        echo 'Ungültiges Zeitformat. Bitte HH:MM verwenden.';
        exit;
    }

    [$h, $m] = array_map('intval', explode(':', $timeFilter));
    $dayStart = strtotime(date('Y-m-d 00:00:00'));
    $target = $dayStart + ($h * 3600) + ($m * 60);
    $range = 15 * 60;
    $where .= ' AND ts BETWEEN :fromTs AND :toTs';
    $params['fromTs'] = $target - $range;
    $params['toTs'] = $target + $range;
} else {
    $where .= ' AND ts >= :minTs';
    $params['minTs'] = time() - ($windowMinutes * 60);
}

$sql = "SELECT id, ts, token FROM photos WHERE {$where} ORDER BY ts DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$photos = $stmt->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fotobox – Letzte Fotos</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
    <h1>Letzte <?= htmlspecialchars((string) $windowMinutes, ENT_QUOTES, 'UTF-8') ?> Minuten</h1>
    <p><a href="all.php">Alle Fotos</a> · <a href="order.php">Meine Bestellung</a></p>

    <form method="get" class="filter">
        <label for="time">Filter nach Uhrzeit (HH:MM):</label>
        <input type="time" id="time" name="time" value="<?= htmlspecialchars((string) $timeFilter, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Filtern</button>
    </form>

    <section class="grid">
        <?php foreach ($photos as $photo): ?>
            <a class="card" href="photo.php?t=<?= urlencode($photo['token']) ?>">
                <img src="media.php?type=thumb&t=<?= urlencode($photo['token']) ?>" alt="Foto">
                <small><?= date('H:i', (int) $photo['ts']) ?></small>
            </a>
        <?php endforeach; ?>
    </section>
</main>
</body>
</html>
