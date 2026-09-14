<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Exception\UnauthorizedException;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Auth\SessionAuth;

/**
 * Everything under /api and /media needs a session, except login and the health probe.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    private const PUBLIC = [['POST', '/api/auth/login'], ['GET', '/api/health']];

    public function __construct(private readonly SessionAuth $auth)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $guarded = $request->isApi() || str_starts_with($request->path, '/media/');
        if ($guarded && !in_array([$request->method, rtrim($request->path, '/')], self::PUBLIC, true) && !$this->auth->isAuthenticated()) {
            throw new UnauthorizedException();
        }
        return $next($request);
    }
}
