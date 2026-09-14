<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\AiService;
use App\Domain\Ai\AiProviderInterface;
use App\Domain\Ai\ProviderRegistry;
use App\Domain\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class AiServiceTest extends TestCase
{
    private function service(): AiService
    {
        $provider = new class () implements AiProviderInterface {
            public function translate(string $text, string $targetCountry): string
            {
                return "[$targetCountry] $text";
            }

            public function authorName(string $country, string $gender): string
            {
                return "$gender-$country";
            }

            public function name(): string
            {
                return 'Mock';
            }
        };
        return new AiService(new ProviderRegistry(['mock' => $provider]));
    }

    public function testListProviders(): void
    {
        self::assertSame([['id' => 'mock', 'name' => 'Mock']], $this->service()->listProviders());
    }

    public function testTranslate(): void
    {
        self::assertSame(['text' => '[SI] Hello'], $this->service()->translate('mock', 'Hello', 'si'));
    }

    public function testTranslateRejectsUnknownProvider(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->translate('nope', 'Hello', 'si');
    }

    public function testTranslateRejectsBlankTextAndBadCountry(): void
    {
        try {
            $this->service()->translate('mock', '   ', 'six');
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('text', $e->getFields());
            self::assertArrayHasKey('target_country', $e->getFields());
        }
    }

    public function testAuthorName(): void
    {
        self::assertSame(['name' => 'male-SI'], $this->service()->authorName('mock', 'si', 'male'));
    }

    public function testAuthorNameRejectsBadGender(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->authorName('mock', 'si', 'other');
    }
}
