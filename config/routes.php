<?php

declare(strict_types=1);

use App\Http\Controller\HealthController;
use App\Http\Controller\HomeController;
use App\Http\Controller\ProductController;
use App\Http\Controller\SyncController;
use App\Http\Controller\TestimonialController;
use App\Http\Router;

return static function (Router $r): void {
    $r->get('/', [HomeController::class, 'index']);
    $r->get('/api/health', [HealthController::class, 'show']);
    $r->post('/api/landings/sync', [SyncController::class, 'run']);
    $r->get('/api/sync/last', [SyncController::class, 'last']);
    $r->get('/api/products', [ProductController::class, 'index']);
    $r->get('/api/products/{sku}/landings', [ProductController::class, 'landings']);
    $r->get('/api/landings/{id}/testimonials', [TestimonialController::class, 'index']);
    $r->post('/api/landings/{id}/testimonials', [TestimonialController::class, 'store']);
    $r->get('/api/testimonials/{id}', [TestimonialController::class, 'show']);
    $r->patch('/api/testimonials/{id}', [TestimonialController::class, 'update']);
    $r->delete('/api/testimonials/{id}', [TestimonialController::class, 'destroy']);
};
