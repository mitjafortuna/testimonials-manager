<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Ai;

use App\Domain\Ai\AiProviderInterface;
use App\Domain\Ai\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    private function provider(string $name): AiProviderInterface
    {
        return new class ($name) implements AiProviderInterface {
            public function __construct(private string $n)
            {
            }

            public function translate(string $text, string $targetCountry): string
            {
                return $text;
            }

            public function authorName(string $country, string $gender): string
            {
                return 'X';
            }

            public function name(): string
            {
                return $this->n;
            }
        };
    }

    public function testAllListsIdsAndNames(): void
    {
        $r = new ProviderRegistry(['a' => $this->provider('A'), 'b' => $this->provider('B')]);
        self::assertSame([['id' => 'a', 'name' => 'A'], ['id' => 'b', 'name' => 'B']], $r->all());
    }

    public function testHasAndGet(): void
    {
        $p = $this->provider('A');
        $r = new ProviderRegistry(['a' => $p]);
        self::assertTrue($r->has('a'));
        self::assertFalse($r->has('missing'));
        self::assertSame($p, $r->get('a'));
    }

    public function testGetUnknownThrows(): void
    {
        $r = new ProviderRegistry([]);
        $this->expectException(\InvalidArgumentException::class);
        $r->get('nope');
    }
}
