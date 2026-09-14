<?php

declare(strict_types=1);

namespace App;

/**
 * Minimal closure-based service container: each id resolves once and is cached.
 */
final class Container
{
    /** @var array<string, callable(Container): object> */
    private array $factories = [];
    /** @var array<string, object> */
    private array $instances = [];

    /** @param callable(Container): object $factory */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function get(string $id): object
    {
        if (!isset($this->instances[$id])) {
            if (!isset($this->factories[$id])) {
                throw new \RuntimeException("No service registered for '$id'");
            }
            $this->instances[$id] = ($this->factories[$id])($this);
        }
        return $this->instances[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }
}
