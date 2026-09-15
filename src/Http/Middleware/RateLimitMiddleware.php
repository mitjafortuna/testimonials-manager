<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Exception\TooManyRequestsException;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\RateLimit\FileRateLimiter;

/**
 * Per-IP request throttle for /api. Login gets its own tighter bucket (the documented gap: no
 * brute-force protection on auth); every other API route shares a looser general bucket.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private const LOGIN = ['POST', '/api/auth/login'];

    public function __construct(
        private readonly FileRateLimiter $limiter,
        private readonly int $loginLimit,
        private readonly int $loginWindowSeconds,
        private readonly int $apiLimit,
        private readonly int $apiWindowSeconds,
        private readonly bool $enabled = true,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$this->enabled || !$request->isApi()) {
            return $next($request);
        }

        $ip = $request->ip !== '' ? $request->ip : 'unknown';
        $isLogin = [$request->method, rtrim($request->path, '/')] === self::LOGIN;
        [$bucket, $limit, $window] = $isLogin
            ? ['login', $this->loginLimit, $this->loginWindowSeconds]
            : ['api', $this->apiLimit, $this->apiWindowSeconds];

        if ($this->limiter->tooManyAttempts("$bucket:$ip", $limit, $window)) {
            throw new TooManyRequestsException();
        }

        return $next($request);
    }
}
