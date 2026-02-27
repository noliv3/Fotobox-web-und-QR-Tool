<?php
// Bootstrap (Stub)
// TODO: Konfiguration aus produktiver Datei laden.

$config = require __DIR__ . '/config.example.php';

// TODO: Basispfade und Laufzeitkontext zentralisieren.
define('APP_ROOT', dirname(__DIR__));
define('DATA_ROOT', $config['data_path']);
