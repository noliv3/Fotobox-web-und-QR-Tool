<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();

$cfg = config();
$hash = trim((string) ($cfg['admin_password_hash'] ?? ''));
$adminEnabled = $hash !== '' && $hash !== 'CHANGE_ME';

if (!$adminEnabled) {
    http_response_code(403);
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Admin deaktiviert</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
    <main class="container narrow panel stack">
        <h1>403 – Admin deaktiviert</h1>
        <p>Admin deaktiviert. Setze admin_password_hash in shared/config.php.</p>
        <p><a href="index.php">Zurück zur Galerie</a></p>
    </main>
    </body>
    </html>
    <?php
    exit;
}

session_name('pb_admin');
session_start();

$error = '';

if (($_POST['action'] ?? '') === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (!($_SESSION['admin_ok'] ?? false) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    if (password_verify($password, $hash)) {
        $_SESSION['admin_ok'] = true;
        header('Location: admin.php');
        exit;
    }

    $error = 'Login fehlgeschlagen.';
}

if (!($_SESSION['admin_ok'] ?? false)) {
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Admin Login</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
    <main class="container narrow">
        <h1>Admin Login</h1>
        <?php if ($error !== ''): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post" class="panel stack">
            <label for="password">Passwort</label>
            <input id="password" name="password" type="password" required>
            <button type="submit">Anmelden</button>
        </form>
        <p><a href="index.php">Zurück zur Galerie</a></p>
    </main>
    </body>
    </html>
    <?php
    exit;
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Galerie Admin</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container narrow panel stack">
    <h1>Admin OK</h1>
    <p>Admin-Bereich ist aktiv.</p>
    <p><a href="index.php">Zur öffentlichen Galerie</a></p>
    <form method="post">
        <button type="submit" name="action" value="logout">Logout</button>
    </form>
</main>
</body>
</html>
