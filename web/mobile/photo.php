<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();

$pdo = pdo();
$token = $_GET['t'] ?? '';
if (!is_string($token) || !isValidToken($token)) {
    http_response_code(400);
    echo 'invalid_token';
    exit;
}

$photo = findPhotoByToken($pdo, $token);
if ($photo === null) {
    http_response_code(404);
    echo 'photo_not_found';
    exit;
}

$printable = nowTs() - (int) $photo['ts'] <= ((int) config()['gallery_window_minutes'] * 60);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Foto</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
    <p class="nav-links"><a href="index.php">Zur Galerie</a> <a href="order.php">Meine Bestellung</a></p>
    <img class="hero" src="image.php?t=<?= urlencode($token) ?>&amp;type=original" alt="Foto">

    <div class="panel actions">
        <a class="button" href="download.php?t=<?= urlencode($token) ?>">Download</a>
        <?php if ($printable): ?>
            <form method="post" action="api_print.php" class="inline-form">
                <input type="hidden" name="t" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="print_api_key" value="<?= htmlspecialchars((string) config()['print_api_key'], ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit">Drucken</button>
            </form>
        <?php else: ?>
            <span class="muted">Druck nur im Zeitfenster möglich.</span>
        <?php endif; ?>
    </div>

    <form method="post" action="api_mark.php" class="panel form-stack">
        <input type="hidden" name="t" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <label for="guest_name">Name für Bestellung (optional)</label>
        <input id="guest_name" name="guest_name" maxlength="80" autocomplete="name">
        <button type="submit">Zur Bestellung hinzufügen</button>
    </form>
</main>
</body>
</html>
