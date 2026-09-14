<?php

declare(strict_types=1);

use App\Http\Controller\AiController;
use App\Http\Controller\AuthController;
use App\Http\Controller\HealthController;
use App\Http\Controller\HomeController;
use App\Http\Controller\ImageController;
use App\Http\Controller\MediaController;
use App\Http\Controller\ProductController;
use App\Http\Controller\SyncController;
use App\Http\Controller\TestimonialController;
use App\Http\Router;

return static function (Router $r): void {
    $r->get('/', [HomeController::class, 'index']);
    $r->get('/api/health', [HealthController::class, 'show']);
    $r->post('/api/auth/login', [AuthController::class, 'login']);
    $r->post('/api/auth/logout', [AuthController::class, 'logout']);
    $r->get('/api/auth/me', [AuthController::class, 'me']);
    $r->post('/api/landings/sync', [SyncController::class, 'run']);
    $r->get('/api/sync/last', [SyncController::class, 'last']);
    $r->get('/api/products', [ProductController::class, 'index']);
    $r->get('/api/products/{sku}/landings', [ProductController::class, 'landings']);
    $r->get('/api/landings/{id}/testimonials', [TestimonialController::class, 'index']);
    $r->post('/api/landings/{id}/testimonials', [TestimonialController::class, 'store']);
    $r->get('/api/testimonials/{id}', [TestimonialController::class, 'show']);
    $r->patch('/api/testimonials/{id}', [TestimonialController::class, 'update']);
    $r->delete('/api/testimonials/{id}', [TestimonialController::class, 'destroy']);
    $r->post('/api/testimonials/{id}/images', [ImageController::class, 'store']);
    $r->delete('/api/images/{id}', [ImageController::class, 'destroy']);
    $r->get('/api/ai/providers', [AiController::class, 'providers']);
    $r->post('/api/ai/translate', [AiController::class, 'translate']);
    $r->post('/api/ai/author-name', [AiController::class, 'authorName']);
    $r->get('/media/{filename}', [MediaController::class, 'show']);
};
