<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class UpstreamException extends HttpException
{
    public function __construct(string $message = 'Upstream service failed')
    {
        parent::__construct(502, 'upstream_error', $message);
    }
}
