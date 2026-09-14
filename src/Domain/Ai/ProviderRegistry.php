<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class ProviderRegistry
{
    /** @param array<string,AiProviderInterface> $providers  id => instance */
    public function __construct(private readonly array $providers)
    {
    }

    /** @return list<array{id:string,name:string}> */
    public function all(): array
    {
        $out = [];
        foreach ($this->providers as $id => $p) {
            $out[] = ['id' => $id, 'name' => $p->name()];
        }
        return $out;
    }

    public function has(string $id): bool
    {
        return isset($this->providers[$id]);
    }

    public function get(string $id): AiProviderInterface
    {
        if (!isset($this->providers[$id])) {
            throw new \InvalidArgumentException("Unknown AI provider '$id'");
        }
        return $this->providers[$id];
    }
}
