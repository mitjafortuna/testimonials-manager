<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class ValidationException extends HttpException
{
    /** @param array<string,string> $fields */
    public function __construct(array $fields, string $message = 'Validation failed')
    {
        parent::__construct(422, 'validation_failed', $message, $fields);
    }
}
