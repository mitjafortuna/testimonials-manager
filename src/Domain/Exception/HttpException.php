<?php

declare(strict_types=1);

namespace App\Domain\Exception;

class HttpException extends \RuntimeException
{
    /** @param array<string,string> $fields */
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,string> */
    public function getFields(): array
    {
        return $this->fields;
    }
}
