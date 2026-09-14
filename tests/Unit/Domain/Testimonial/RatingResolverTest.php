<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Testimonial;

use App\Domain\Testimonial\RatingResolver;
use PHPUnit\Framework\TestCase;

final class RatingResolverTest extends TestCase
{
    public function testStoredRatingIsReturnedAsFloat(): void
    {
        self::assertSame(3.0, (new RatingResolver())->display(3));
    }

    public function testRandomRatingUsesInjectedSource(): void
    {
        $r = new RatingResolver(fn () => 7);   // 7 tenths above 4.0
        self::assertSame(4.7, $r->display(null));
    }

    public function testRandomRatingStaysInRange(): void
    {
        $r = new RatingResolver();
        for ($i = 0; $i < 200; $i++) {
            $v = $r->display(null);
            self::assertGreaterThanOrEqual(4.0, $v);
            self::assertLessThanOrEqual(5.0, $v);
            self::assertSame(round($v, 1), $v);
        }
    }
}
