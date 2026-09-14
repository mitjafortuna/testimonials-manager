<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Domain\Exception\HttpException;
use App\Domain\Exception\NotFoundException;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/api/products', ['ProductsCtl', 'index']);
        $this->router->get('/api/products/{sku}/landings', ['ProductsCtl', 'landings']);
        $this->router->patch('/api/testimonials/{id}', ['TestimonialsCtl', 'update']);
    }

    public function testMatchesStaticRoute(): void
    {
        $m = $this->router->match('GET', '/api/products');
        self::assertSame(['ProductsCtl', 'index'], $m->handler);
        self::assertSame([], $m->params);
    }

    public function testMatchesParams(): void
    {
        $m = $this->router->match('GET', '/api/products/ab-forge/landings');
        self::assertSame(['sku' => 'ab-forge'], $m->params);
        self::assertSame(['id' => '42'], $this->router->match('PATCH', '/api/testimonials/42')->params);
    }

    public function testTrailingSlashIsTolerated(): void
    {
        self::assertSame(['ProductsCtl', 'index'], $this->router->match('GET', '/api/products/')->handler);
    }

    public function testUnknownPathIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->router->match('GET', '/api/nope');
    }

    public function testWrongMethodIs405(): void
    {
        try {
            $this->router->match('DELETE', '/api/products');
            self::fail('expected 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->getStatus());
            self::assertSame('method_not_allowed', $e->getErrorCode());
        }
    }
}
