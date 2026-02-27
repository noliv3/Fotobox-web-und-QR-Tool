<?php

declare(strict_types=1);

return [
    'base_url' => 'http://localhost:8000/web/gallery',
    'base_url_mobile' => 'http://localhost:8000/web/mobile',
    'watch_path' => __DIR__ . '/../data/watch',
    'data_path' => __DIR__ . '/../data',
    'timezone' => 'Europe/Vienna',
    'retention_days' => 30,
    'gallery_window_minutes' => 15,
    'print_api_key' => 'CHANGE_ME_PRINT_API_KEY',
    'admin_password_hash_placeholder' => 'CHANGE_ME_PASSWORD_HASH',
    'rate_limit_max' => 5,
    'rate_limit_window_seconds' => 600,
];
