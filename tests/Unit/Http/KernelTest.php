<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Container;
use App\Domain\Exception\ValidationException;
use App\Http\Kernel;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    /** @param list<MiddlewareInterface> $middleware */
    private function kernel(bool $debug = false, array $middleware = []): Kernel
    {
        $c = new Container();
        $c->set(FakeController::class, fn () => new FakeController());
        $router = new Router();
        $router->get('/api/ok', [FakeController::class, 'ok']);
        $router->get('/api/invalid', [FakeController::class, 'invalid']);
        $router->get('/api/boom', [FakeController::class, 'boom']);
        $router->get('/api/echo/{id}', [FakeController::class, 'echo']);
        return new Kernel($c, $router, $middleware, $debug);
    }

    public function testDispatchesToController(): void
    {
        $r = $this->kernel()->handle(new Request('GET', '/api/ok'));
        self::assertSame(200, $r->status);
        self::assertSame(['ok' => true], json_decode($r->body, true));
    }

    public function testRouteParamsArePassedAsAttributes(): void
    {
        $r = $this->kernel()->handle(new Request('GET', '/api/echo/99'));
        self::assertSame(['id' => '99'], json_decode($r->body, true));
    }

    public function testHttpExceptionBecomesErrorResponse(): void
    {
        $r = $this->kernel()->handle(new Request('GET', '/api/invalid'));
        self::assertSame(422, $r->status);
        self::assertSame('validation_failed', json_decode($r->body, true)['error']['code']);
        self::assertSame(['text' => 'Required'], json_decode($r->body, true)['error']['fields']);
    }

    public function testUnknownRouteIs404Json(): void
    {
        $r = $this->kernel()->handle(new Request('GET', '/api/nothing'));
        self::assertSame(404, $r->status);
        self::assertSame('not_found', json_decode($r->body, true)['error']['code']);
    }

    public function testUnexpectedExceptionIs500AndHidesMessageUnlessDebug(): void
    {
        $r = $this->kernel(false)->handle(new Request('GET', '/api/boom'));
        self::assertSame(500, $r->status);
        self::assertSame('Internal server error', json_decode($r->body, true)['error']['message']);
        $r = $this->kernel(true)->handle(new Request('GET', '/api/boom'));
        self::assertStringContainsString('kaboom', json_decode($r->body, true)['error']['message']);
    }

    public function testMiddlewareRunsInOrderAndCanShortCircuit(): void
    {
        $log = [];
        $mw1 = new CallbackMiddleware(
            function (Request $request, callable $next) use (&$log): Response {
                $log[] = 'a';
                return $next($request);
            }
        );
        $mw2 = new class () implements MiddlewareInterface {
            public function process(Request $request, callable $next): Response
            {
                return Response::json(['short' => true], 418);
            }
        };
        $r = $this->kernel(false, [$mw1, $mw2])->handle(new Request('GET', '/api/ok'));
        self::assertSame(418, $r->status);
        self::assertSame(['a'], $log);
    }
}

/**
 * Test-only middleware that delegates to a closure, used to log side effects
 * without promoting a by-reference constructor property (phpstan-unfriendly).
 */
final class CallbackMiddleware implements MiddlewareInterface
{
    /** @param \Closure(Request, callable(Request): Response): Response $callback */
    public function __construct(private readonly \Closure $callback)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        return ($this->callback)($request, $next);
    }
}

final class FakeController
{
    public function ok(Request $r): Response
    {
        return Response::json(['ok' => true]);
    }

    public function echo(Request $r): Response
    {
        return Response::json(['id' => $r->attribute('id')]);
    }

    public function invalid(Request $r): Response
    {
        throw new ValidationException(['text' => 'Required']);
    }

    public function boom(Request $r): Response
    {
        throw new \RuntimeException('kaboom');
    }
}
