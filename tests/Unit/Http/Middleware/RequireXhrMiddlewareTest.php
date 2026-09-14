<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Domain\Exception\ForbiddenException;
use App\Http\Middleware\RequireXhrMiddleware;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

final class RequireXhrMiddlewareTest extends TestCase
{
    private RequireXhrMiddleware $mw;

    protected function setUp(): void
    {
        $this->mw = new RequireXhrMiddleware();
    }

    public function testGetPassesWithoutHeader(): void
    {
        $r = $this->mw->process(new Request('GET', '/api/products'), fn () => Response::noContent());
        self::assertSame(204, $r->status);
    }

    public function testMutatingApiRequestWithoutHeaderIsForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->mw->process(new Request('POST', '/api/landings/sync'), fn () => Response::noContent());
    }

    public function testMutatingApiRequestWithHeaderPasses(): void
    {
        $r = $this->mw->process(new Request('DELETE', '/api/testimonials/1', headers: ['X-Requested-With' => 'XMLHttpRequest']), fn () => Response::noContent());
        self::assertSame(204, $r->status);
    }

    public function testNonApiPostIsNotGuarded(): void
    {
        $r = $this->mw->process(new Request('POST', '/other'), fn () => Response::noContent());
        self::assertSame(204, $r->status);
    }
}
