<?php
declare(strict_types=1);

return [
    'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'db_name' => getenv('DB_NAME') ?: 'online_egitim',
    'db_user' => getenv('DB_USER') ?: 'root',
    'db_pass' => getenv('DB_PASS') ?: '',
    'upload_dir' => __DIR__ . DIRECTORY_SEPARATOR . 'uploads',
    'max_upload_bytes' => 25 * 1024 * 1024,
];
