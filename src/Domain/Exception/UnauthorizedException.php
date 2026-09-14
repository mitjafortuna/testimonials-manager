<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Authentication required')
    {
        parent::__construct(401, 'unauthorized', $message);
    }
}
