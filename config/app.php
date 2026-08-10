<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'name' => Env::get('APP_NAME', 'Bioscem Shop'),
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => filter_var(Env::get('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
    'url' => Env::get('APP_URL', ''),
    'timezone' => Env::get('APP_TIMEZONE', 'Europe/Bucharest'),
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', 3306),
        'database' => Env::get('DB_DATABASE', ''),
        'username' => Env::get('DB_USERNAME', ''),
        'password' => Env::get('DB_PASSWORD', ''),
    ],
    'admin' => [
        'default_email' => Env::get('ADMIN_DEFAULT_EMAIL', 'admin@example.com'),
        'default_password' => Env::get('ADMIN_DEFAULT_PASSWORD', 'ChangeMe123!'),
    ],
];
