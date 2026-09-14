<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class ClaudeProvider extends AbstractMockProvider
{
    public function name(): string
    {
        return 'Claude (mock)';
    }

    protected function sampleNames(): array
    {
        return ['male' => ['Leo', 'Max', 'Theo'], 'female' => ['Nora', 'Iris', 'June'], 'unisex' => ['Robin', 'Sage', 'Wren']];
    }
}
