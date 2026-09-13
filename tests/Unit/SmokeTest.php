<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testPhpVersionIsAtLeast81(): void
    {
        self::assertTrue(version_compare(PHP_VERSION, '8.1.0', '>='));
    }
}
