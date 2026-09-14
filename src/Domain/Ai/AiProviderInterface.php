<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * A mock stand-in for a real translation/generation provider. Every implementation is pure and
 * deterministic-ish (random only within a fixed sample pool) — no network calls, safe to unit test.
 */
interface AiProviderInterface
{
    public function translate(string $text, string $targetCountry): string;

    public function authorName(string $country, string $gender): string;

    public function name(): string;
}
