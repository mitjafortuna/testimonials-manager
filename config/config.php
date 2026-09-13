<?php

declare(strict_types=1);

use App\Support\Env;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$uploadDir = Env::get('UPLOAD_DIR', 'storage/uploads') ?? 'storage/uploads';

return [
    'root' => $root,
    'env' => Env::get('APP_ENV', 'prod'),
    'debug' => in_array(Env::get('APP_DEBUG', '0'), ['1', 'true'], true),
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', '3306'),
        'name' => Env::get('DB_NAME', 'testimonials'),
        'user' => Env::get('DB_USER', 'root'),
        'pass' => Env::get('DB_PASS', ''),
    ],
    'upload' => [
        'dir' => str_starts_with($uploadDir, '/') ? $uploadDir : $root . '/' . $uploadDir,
        'max_bytes' => (int) Env::get('UPLOAD_MAX_BYTES', '5242880'),
    ],
    'landings_api' => [
        'url' => Env::get('LANDINGS_API_URL', ''),
        'key' => Env::get('LANDINGS_API_KEY', ''),
        'fixture' => Env::get('LANDINGS_API_FIXTURE') ?: null,
    ],
    'session' => ['name' => Env::get('SESSION_NAME', 'tm_session')],
];
