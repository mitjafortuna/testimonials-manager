<?php

declare(strict_types=1);

namespace App\Http;

use App\Container;
use App\Domain\Exception\HttpException;

final class Kernel
{
    /** @param list<MiddlewareInterface> $middleware */
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly array $middleware,
        private readonly bool $debug,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $pipeline = array_reduce(
                array_reverse($this->middleware),
                fn (callable $next, MiddlewareInterface $mw) => fn (Request $r) => $mw->process($r, $next),
                fn (Request $r) => $this->dispatch($r),
            );
            return $pipeline($request);
        } catch (HttpException $e) {
            return Response::error($e->getStatus(), $e->getErrorCode(), $e->getMessage(), $e->getFields());
        } catch (\Throwable $e) {
            error_log(sprintf('[500] %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            $message = $this->debug ? $e::class . ': ' . $e->getMessage() : 'Internal server error';
            return Response::error(500, 'internal_error', $message);
        }
    }

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request->method, $request->path);
        [$class, $method] = $match->handler;
        $controller = $this->container->get($class);
        return $controller->$method($request->withAttributes($match->params));
    }
}
