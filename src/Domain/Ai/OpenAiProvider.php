<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class OpenAiProvider extends AbstractMockProvider
{
    public function name(): string
    {
        return 'OpenAI (mock)';
    }

    protected function sampleNames(): array
    {
        return ['male' => ['Alex', 'Sam', 'Jordan'], 'female' => ['Taylor', 'Riley', 'Morgan'], 'unisex' => ['Casey', 'Drew', 'Jamie']];
    }
}
