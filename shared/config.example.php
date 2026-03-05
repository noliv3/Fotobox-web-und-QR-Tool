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
    'db_path' => __DIR__ . '/../data/queue/photobox.sqlite',
    'watch_path' => __DIR__ . '/../data/watch', // Standard-Watch-Ordner
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
    'upload_print_max_files' => 12,            // max. Uploads je mobile Session
    'upload_print_max_total_mb' => 80,         // max. Upload-Speicher je mobile Session
    'upload_print_max_age_hours' => 12,        // Ablauf je Upload in Session
    'upload_print_retention_hours' => 24,      // Cleanup für alte Upload-Session-Ordner
    'upload_print_max_dimension' => 12000,     // max. Breite/Höhe in Pixel


    // Orders
    'paypal_me_base_url' => 'https://paypal.me/DEINNAME',
    'order_zip_dir' => __DIR__ . '/../data/orders',
    'order_max_age_hours' => 24,

    // Admin
    'admin_password_hash' => 'CHANGE_ME',      // optional, aktiviert Passwort-Login
    'admin_code' => 'CHANGE_ME_ADMIN_CODE',    // neuer stiller Admin-Gate Code

    // Rate Limit
    'rate_limit_max' => 6,
    'rate_limit_window_seconds' => 60,
];

