<?php

declare(strict_types=1);

use App\Http\Controller\HealthController;
use App\Http\Controller\HomeController;
use App\Http\Router;

return static function (Router $r): void {
    $r->get('/', [HomeController::class, 'index']);
    $r->get('/api/health', [HealthController::class, 'show']);
};
