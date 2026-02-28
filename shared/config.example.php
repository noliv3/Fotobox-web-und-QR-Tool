<?php

declare(strict_types=1);

return [
    'base_url' => 'http://photobox:8080/',
    'base_url_mobile' => 'http://photobox:8080/mobile/',
    'watch_path' => __DIR__ . '/../data/watch',
    'import_mode' => 'watch_folder', // watch_folder | sd_card
    'sd_card_path' => 'F:\\DCIM',
    'data_path' => __DIR__ . '/../data',
    'timezone' => 'Europe/Vienna',
    'port' => 8080,
    'retention_days' => 30,
    'gallery_window_minutes' => 15,
    'camera_idle_minutes' => 30,
    'printer_name' => '',
    'print_api_key' => 'CHANGE_ME_PRINT_API_KEY',
    // Hash erzeugen mit: php -r "echo password_hash('DEINPASS', PASSWORD_DEFAULT), PHP_EOL;"
    'admin_password_hash' => 'CHANGE_ME',
    'rate_limit_max' => 6,
    'rate_limit_window_seconds' => 60,
];
