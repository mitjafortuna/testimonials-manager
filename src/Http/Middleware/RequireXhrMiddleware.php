<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Exception\ForbiddenException;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Http\Response;

/**
 * CSRF guard: mutating /api requests must carry X-Requested-With, which cross-site forms cannot set.
 */
final class RequireXhrMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        if ($request->isApi() && !in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true) && !$request->isXhr()) {
            throw new ForbiddenException('Missing X-Requested-With header');
        }
        return $next($request);
    }
}
