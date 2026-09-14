<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Shared mock behaviour: translate() just tags the text with the target country (a real provider
 * would call out to an actual translation API — this keeps the call site identical for later).
 * Subclasses only differ in name() and their sample name pool per gender.
 */
abstract class AbstractMockProvider implements AiProviderInterface
{
    public function translate(string $text, string $targetCountry): string
    {
        return sprintf('[%s] %s', strtoupper($targetCountry), $text);
    }

    public function authorName(string $country, string $gender): string
    {
        $pool = $this->sampleNames();
        $names = $pool[$gender] ?? $pool['unisex'];
        return $names[array_rand($names)];
    }

    /** @return array{male:list<string>,female:list<string>,unisex:list<string>} */
    abstract protected function sampleNames(): array;
}
