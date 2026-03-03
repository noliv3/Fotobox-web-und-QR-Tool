<?php

declare(strict_types=1);

return [
    // Server/URLs
    'host' => '0.0.0.0',
    'port' => 8080,
    'base_url' => 'http://photobox:8080/',
    'base_url_mobile' => 'http://photobox:8080/mobile/',
    'timezone' => 'Europe/Vienna',

    // Pfade
    'data_path' => __DIR__ . '/../data',
    'db_path' => __DIR__ . '/../data/index.sqlite',
    'watch_path' => 'D:/TEST',                 // Test: hier JPGs reinlegen
    'watch_extensions' => ['jpg', 'jpeg'],     // Importfilter
    'import_mode' => 'watch_folder',           // watch_folder | sd_card
    'sd_card_path' => 'F:/DCIM',               // nur relevant bei import_mode=sd_card

    // Import/Anzeige
    'gallery_window_minutes' => 15,
    'camera_idle_minutes' => 30,

    // Retention/Cleanup
    'retention_days' => 30,

    // Printing
    'printer_name' => '',                      // optional, je nach Implementierung
    'print_api_key' => 'CHANGE_ME_PRINT_API_KEY',


    // Orders
    'paypal_me_base_url' => 'https://paypal.me/DEINNAME',
    'order_zip_dir' => __DIR__ . '/../data/orders',
    'order_max_age_hours' => 24,

    // Admin
    'admin_password_hash' => 'CHANGE_ME',      // legacy, optional
    'admin_code' => 'CHANGE_ME_ADMIN_CODE',    // neuer stiller Admin-Gate Code

    // Rate Limit
    'rate_limit_max' => 6,
    'rate_limit_window_seconds' => 60,
];
