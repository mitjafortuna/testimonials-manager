<?php

declare(strict_types=1);

// PHP built-in server (CI / dev without Apache): serve existing static files directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if ($file !== __DIR__ . '/' && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
$container = (require dirname(__DIR__) . '/config/container.php')($config);

$container->get(App\Http\Kernel::class)
    ->handle(App\Http\Request::fromGlobals())
    ->send();
