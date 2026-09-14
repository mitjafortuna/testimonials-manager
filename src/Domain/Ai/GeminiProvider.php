<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class GeminiProvider extends AbstractMockProvider
{
    public function name(): string
    {
        return 'Gemini (mock)';
    }

    protected function sampleNames(): array
    {
        return ['male' => ['Noah', 'Liam', 'Ethan'], 'female' => ['Ava', 'Mia', 'Zoe'], 'unisex' => ['Sky', 'River', 'Quinn']];
    }
}
