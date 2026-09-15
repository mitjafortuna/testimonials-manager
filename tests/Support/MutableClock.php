<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Clock;

final class MutableClock implements Clock
{
    public function __construct(public \DateTimeImmutable $time)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->time;
    }
}
