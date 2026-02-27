<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

$token = $_GET['t'] ?? '';
if (!is_string($token) || !validate_token($token)) {
    http_response_code(400);
    echo 'Ungültiger Token.';
    exit;
}

$photo = find_photo_by_token($token);
if (!$photo) {
    http_response_code(404);
    echo 'Foto nicht gefunden.';
    exit;
}

$printable = is_photo_printable($photo);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fotobox – Foto</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container detail">
    <p><a href="index.php">← Zurück</a> · <a href="order.php">Meine Bestellung</a></p>
    <img class="hero" src="media.php?type=photo&t=<?= urlencode($token) ?>" alt="Detailfoto">

    <div class="actions">
        <a class="button" href="media.php?type=download&t=<?= urlencode($token) ?>">Download</a>

        <?php if ($printable): ?>
            <button class="button" data-print-token="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">Drucken</button>
        <?php else: ?>
            <span class="muted">Drucken nur im Zeitfenster möglich.</span>
        <?php endif; ?>
    </div>

    <form id="markForm" class="mark-form">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <label for="guest_name">Name für Merkliste:</label>
        <input id="guest_name" name="guest_name" maxlength="80" placeholder="Vorname / Tisch">
        <button class="button" type="submit">Merken</button>
    </form>

    <p id="apiMessage" class="muted"></p>
</main>
<script src="app.js"></script>
</body>
</html>
