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
    'rate_limit' => [
        // Off under APP_ENV=test: the API/e2e suites log in once per test, back-to-back, from one IP.
        'enabled' => Env::get('APP_ENV', 'prod') !== 'test',
        'dir' => $root . '/storage/ratelimit',
        'login_limit' => (int) Env::get('RATE_LIMIT_LOGIN', '10'),
        'login_window_seconds' => (int) Env::get('RATE_LIMIT_LOGIN_WINDOW', '60'),
        'api_limit' => (int) Env::get('RATE_LIMIT_API', '120'),
        'api_window_seconds' => (int) Env::get('RATE_LIMIT_API_WINDOW', '60'),
    ],
];
