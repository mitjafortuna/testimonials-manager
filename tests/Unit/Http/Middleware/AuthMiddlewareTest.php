<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Domain\Exception\UnauthorizedException;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Auth\SessionAuth;
use PHPUnit\Framework\TestCase;

final class AuthMiddlewareTest extends TestCase
{
    private function mw(bool $authenticated): AuthMiddleware
    {
        $auth = $this->createMock(SessionAuth::class);
        $auth->method('isAuthenticated')->willReturn($authenticated);
        return new AuthMiddleware($auth);
    }

    /** @return iterable<string, array{0:string,1:string}> */
    public static function publicRoutes(): iterable
    {
        yield 'login' => ['POST', '/api/auth/login'];
        yield 'health' => ['GET', '/api/health'];
        yield 'spa' => ['GET', '/'];
    }

    /** @dataProvider publicRoutes */
    public function testPublicRoutesPassWhenLoggedOut(string $method, string $path): void
    {
        $r = $this->mw(false)->process(new Request($method, $path), fn () => Response::noContent());
        self::assertSame(204, $r->status);
    }

    /** @return iterable<string, array{0:string,1:string}> */
    public static function protectedRoutes(): iterable
    {
        yield 'products' => ['GET', '/api/products'];
        yield 'me' => ['GET', '/api/auth/me'];
        yield 'sync' => ['POST', '/api/landings/sync'];
        yield 'media' => ['GET', '/media/ffffffff-ffff-4fff-8fff-ffffffffffff.jpg'];
        yield 'logout' => ['POST', '/api/auth/logout'];
    }

    /** @dataProvider protectedRoutes */
    public function testProtectedRoutesRequireSession(string $method, string $path): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->mw(false)->process(new Request($method, $path), fn () => Response::noContent());
    }

    /** @dataProvider protectedRoutes */
    public function testProtectedRoutesPassWhenLoggedIn(string $method, string $path): void
    {
        $r = $this->mw(true)->process(new Request($method, $path), fn () => Response::noContent());
        self::assertSame(204, $r->status);
    }
}
