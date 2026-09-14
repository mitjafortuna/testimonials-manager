<?php

declare(strict_types=1);

namespace App\Domain\Testimonial;

/**
 * A NULL rating means "random": pick 4.0–5.0 at display time so the average stays realistic.
 */
final class RatingResolver
{
    /** @var \Closure(): int  returns tenths above 4.0, i.e. 0..10 */
    private \Closure $random;

    public function __construct(?\Closure $random = null)
    {
        $this->random = $random ?? static fn (): int => random_int(0, 10);
    }

    public function display(?int $rating): float
    {
        if ($rating !== null) {
            return (float) $rating;
        }
        return round(4.0 + ($this->random)() / 10, 1);
    }
}
