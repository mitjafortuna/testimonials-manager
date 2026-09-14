<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Authentication required', string $code = 'unauthorized')
    {
        parent::__construct(401, $code, $message);
    }
}
