<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Domain\Exception\TooManyRequestsException;
use App\Http\Middleware\RateLimitMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\RateLimit\FileRateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimitMiddlewareTest extends TestCase
{
    private function mw(FileRateLimiter $limiter, int $loginLimit = 10, int $apiLimit = 120, bool $enabled = true): RateLimitMiddleware
    {
        return new RateLimitMiddleware($limiter, $loginLimit, 60, $apiLimit, 60, $enabled);
    }

    public function testNonApiRequestsAreNeverThrottled(): void
    {
        $limiter = $this->createMock(FileRateLimiter::class);
        $limiter->expects(self::never())->method('tooManyAttempts');

        $r = $this->mw($limiter)->process(new Request('GET', '/'), fn () => Response::noContent());
        self::assertSame(204, $r->status);
    }

    public function testApiRequestUnderTheLimitPasses(): void
    {
        $limiter = $this->createMock(FileRateLimiter::class);
        $limiter->method('tooManyAttempts')->willReturn(false);

        $r = $this->mw($limiter)->process(new Request('GET', '/api/products', ip: '1.2.3.4'), fn () => Response::noContent());
        self::assertSame(204, $r->status);
    }

    public function testApiRequestOverTheLimitIsRejected(): void
    {
        $limiter = $this->createMock(FileRateLimiter::class);
        $limiter->method('tooManyAttempts')->willReturn(true);

        $this->expectException(TooManyRequestsException::class);
        $this->mw($limiter)->process(new Request('GET', '/api/products', ip: '1.2.3.4'), fn () => Response::noContent());
    }

    public function testLoginUsesItsOwnBucketKeyedByIp(): void
    {
        $limiter = $this->createMock(FileRateLimiter::class);
        $limiter->expects(self::once())
            ->method('tooManyAttempts')
            ->with('login:9.9.9.9', 5, 60)
            ->willReturn(false);

        $this->mw($limiter, loginLimit: 5)->process(
            new Request('POST', '/api/auth/login', ip: '9.9.9.9'),
            fn () => Response::noContent(),
        );
    }

    public function testNonLoginApiRouteUsesTheGeneralBucketKeyedByIp(): void
    {
        $limiter = $this->createMock(FileRateLimiter::class);
        $limiter->expects(self::once())
            ->method('tooManyAttempts')
            ->with('api:9.9.9.9', 42, 60)
            ->willReturn(false);

        $this->mw($limiter, apiLimit: 42)->process(
            new Request('GET', '/api/products', ip: '9.9.9.9'),
            fn () => Response::noContent(),
        );
    }

    public function testDisabledSkipsTheLimiterEntirely(): void
    {
        $limiter = $this->createMock(FileRateLimiter::class);
        $limiter->expects(self::never())->method('tooManyAttempts');

        $r = $this->mw($limiter, enabled: false)->process(
            new Request('POST', '/api/auth/login', ip: '9.9.9.9'),
            fn () => Response::noContent(),
        );
        self::assertSame(204, $r->status);
    }

    public function testMissingIpFallsBackToAnUnknownBucket(): void
    {
        $limiter = $this->createMock(FileRateLimiter::class);
        $limiter->expects(self::once())
            ->method('tooManyAttempts')
            ->with('api:unknown', self::anything(), self::anything())
            ->willReturn(false);

        $this->mw($limiter)->process(new Request('GET', '/api/products'), fn () => Response::noContent());
    }
}
