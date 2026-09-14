<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Ai;

use App\Domain\Ai\ClaudeProvider;
use App\Domain\Ai\GeminiProvider;
use App\Domain\Ai\OpenAiProvider;
use PHPUnit\Framework\TestCase;

final class AbstractMockProviderTest extends TestCase
{
    public function testTranslatePrefixesWithUppercasedCountryCode(): void
    {
        $p = new OpenAiProvider();
        self::assertSame('[SI] Great product!', $p->translate('Great product!', 'si'));
        self::assertSame('[DE] Great product!', $p->translate('Great product!', 'DE'));
    }

    public function testAuthorNamePicksFromTheGenderPool(): void
    {
        $p = new OpenAiProvider();
        for ($i = 0; $i < 20; $i++) {
            self::assertContains($p->authorName('SI', 'male'), ['Alex', 'Sam', 'Jordan']);
            self::assertContains($p->authorName('SI', 'female'), ['Taylor', 'Riley', 'Morgan']);
        }
    }

    public function testAuthorNameFallsBackToUnisexForUnknownGender(): void
    {
        $p = new OpenAiProvider();
        self::assertContains($p->authorName('SI', 'nonbinary'), ['Casey', 'Drew', 'Jamie']);
    }

    public function testEachProviderHasItsOwnNameAndSamplePool(): void
    {
        $openai = new OpenAiProvider();
        $gemini = new GeminiProvider();
        $claude = new ClaudeProvider();
        self::assertSame('OpenAI (mock)', $openai->name());
        self::assertSame('Gemini (mock)', $gemini->name());
        self::assertSame('Claude (mock)', $claude->name());
        self::assertContains($gemini->authorName('SI', 'male'), ['Noah', 'Liam', 'Ethan']);
        self::assertContains($claude->authorName('SI', 'male'), ['Leo', 'Max', 'Theo']);
    }
}
