<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

function mobileEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function mobileRenderLayout(array $params): void
{
    $title = (string) ($params['title'] ?? 'Photobox');
    $statusLine = (string) ($params['status_line'] ?? '');
    $activeView = (string) ($params['active_view'] ?? 'recent');
    $contentHtml = (string) ($params['content_html'] ?? '');
    $csrfToken = getCsrfToken();

    $impressumExists = is_file(ROOT . '/web/impressum.php') || is_file(ROOT . '/web/impressum/index.php');
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= mobileEsc($title) ?></title>
        <link rel="stylesheet" href="/mobile/style.css">
        <meta name="csrf-token" content="<?= mobileEsc($csrfToken) ?>">
    </head>
    <body>
    <div class="mobile-shell">
        <header class="mobile-header">
            <a class="brand-link" href="/mobile/">Photobox</a>
            <div class="status-line"><?= mobileEsc($statusLine) ?></div>
            <button class="menu-button" type="button" aria-label="Menue" data-menu-toggle>&#9776;</button>
        </header>

        <nav class="mobile-tabs" aria-label="Navigation">
            <a class="<?= $activeView === 'recent' ? 'is-active' : '' ?>" href="/mobile/">Neu</a>
            <a class="<?= $activeView === 'all' ? 'is-active' : '' ?>" href="/mobile/?view=all">Alle</a>
            <a class="<?= $activeView === 'favs' ? 'is-active' : '' ?>" href="/mobile/?view=favs">Merkliste</a>
        </nav>

        <div class="menu-overlay" data-menu-overlay hidden>
            <div class="menu-panel" role="dialog" aria-label="Menue">
                <a href="/gallery/">Galerie</a>
                <?php if ($impressumExists): ?>
                    <a href="/impressum">Impressum</a>
                <?php endif; ?>
            </div>
        </div>

        <main class="container">
            <?= $contentHtml ?>
        </main>

        <footer class="mobile-footer">
            <div>I-CRAFT-FOTOBOX</div>
            <div><a href="mailto:Jens@nennertheim.de">Fehler melden: Jens@nennertheim.de</a></div>
        </footer>
    </div>

    <div id="toast-root" class="toast-root" role="status" aria-live="polite"></div>
    <script src="/mobile/app.js"></script>
    </body>
    </html>
    <?php
}
