<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class TooManyRequestsException extends HttpException
{
    public function __construct(string $message = 'Too many requests, slow down')
    {
        parent::__construct(429, 'rate_limited', $message);
    }
}
