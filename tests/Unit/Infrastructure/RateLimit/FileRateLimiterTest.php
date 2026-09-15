<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\RateLimit;

use App\Infrastructure\RateLimit\FileRateLimiter;
use PHPUnit\Framework\TestCase;
use Tests\Support\MutableClock;

final class FileRateLimiterTest extends TestCase
{
    private string $dir;
    private MutableClock $clock;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ratelimit-test-' . bin2hex(random_bytes(8));
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
    }

    private function limiter(): FileRateLimiter
    {
        return new FileRateLimiter($this->dir, $this->clock);
    }

    public function testAllowsAttemptsUnderTheLimit(): void
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < 3; $i++) {
            self::assertFalse($limiter->tooManyAttempts('k', 3, 60));
        }
    }

    public function testBlocksOnceOverTheLimit(): void
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < 3; $i++) {
            $limiter->tooManyAttempts('k', 3, 60);
        }
        self::assertTrue($limiter->tooManyAttempts('k', 3, 60));
    }

    public function testDifferentKeysHaveIndependentCounters(): void
    {
        $limiter = $this->limiter();
        $limiter->tooManyAttempts('a', 1, 60);
        self::assertFalse($limiter->tooManyAttempts('b', 1, 60));
    }

    public function testCounterResetsOnceTheWindowElapses(): void
    {
        $limiter = $this->limiter();
        $limiter->tooManyAttempts('k', 1, 60);
        self::assertTrue($limiter->tooManyAttempts('k', 1, 60));

        $this->clock->time = $this->clock->time->modify('+61 seconds');

        self::assertFalse($limiter->tooManyAttempts('k', 1, 60));
    }
}
