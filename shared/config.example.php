<?php

declare(strict_types=1);

return [
    'base_url' => 'http://photobox:8080/',
    'base_url_mobile' => 'http://photobox:8080/mobile/',
    'watch_path' => __DIR__ . '/../data/watch',
    'data_path' => __DIR__ . '/../data',
    'timezone' => 'Europe/Vienna',
    'port' => 8080,
    'retention_days' => 30,
    'gallery_window_minutes' => 15,
    'camera_idle_minutes' => 30,
    'printer_name' => '',
    'print_api_key' => 'CHANGE_ME_PRINT_API_KEY',
    'admin_password_hash_placeholder' => 'CHANGE_ME_ADMIN_PASSWORD_HASH',
    'rate_limit_max' => 6,
    'rate_limit_window_seconds' => 60,
];
