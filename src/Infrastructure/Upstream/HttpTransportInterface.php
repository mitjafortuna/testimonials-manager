<?php

declare(strict_types=1);

namespace App\Infrastructure\Upstream;

interface HttpTransportInterface
{
    /**
     * @param list<string> $headers  "Name: value" lines
     * @return array{status:int, body:string}
     */
    public function get(string $url, array $headers): array;
}
