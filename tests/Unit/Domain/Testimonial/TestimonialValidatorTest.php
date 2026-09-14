<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Testimonial;

use App\Domain\Exception\ValidationException;
use App\Domain\Testimonial\TestimonialValidator;
use PHPUnit\Framework\TestCase;

final class TestimonialValidatorTest extends TestCase
{
    private TestimonialValidator $v;

    protected function setUp(): void
    {
        $this->v = new TestimonialValidator();
    }

    public function testFullPayloadIsNormalised(): void
    {
        $out = $this->v->validate([
            'author_name' => '  Janez Novak ', 'text' => ' Great! ', 'rating' => '5', 'gender' => 'male',
            'url' => 'https://example.com/x', 'is_active' => '0', 'sort_order' => '3', 'ignored' => 'x',
        ]);
        self::assertSame(['author_name' => 'Janez Novak', 'text' => 'Great!', 'rating' => 5, 'gender' => 'male', 'url' => 'https://example.com/x', 'is_active' => false, 'sort_order' => 3], $out);
    }

    public function testDefaultsWhenOptionalFieldsMissing(): void
    {
        $out = $this->v->validate(['author_name' => 'A', 'text' => 'T']);
        self::assertSame(['author_name' => 'A', 'text' => 'T', 'rating' => null, 'gender' => 'unisex', 'url' => null, 'is_active' => true, 'sort_order' => null], $out);
    }

    public function testRandomRatingSpellings(): void
    {
        foreach ([null, '', 'random', 'RANDOM'] as $r) {
            self::assertNull($this->v->validate(['author_name' => 'A', 'text' => 'T', 'rating' => $r])['rating']);
        }
    }

    public function testEmptyUrlBecomesNull(): void
    {
        self::assertNull($this->v->validate(['author_name' => 'A', 'text' => 'T', 'url' => '  '])['url']);
    }

    public function testPartialOnlyReturnsProvidedKeys(): void
    {
        self::assertSame(['is_active' => true], $this->v->validate(['is_active' => true], true));
        self::assertSame(['text' => 'x'], $this->v->validate(['text' => 'x'], true));
    }

    /** @return iterable<string, array{0: array<string,mixed>, 1: string}> */
    public static function badInputs(): iterable
    {
        yield 'missing name' => [['text' => 'T'], 'author_name'];
        yield 'blank name' => [['author_name' => '   ', 'text' => 'T'], 'author_name'];
        yield 'long name' => [['author_name' => str_repeat('a', 129), 'text' => 'T'], 'author_name'];
        yield 'missing text' => [['author_name' => 'A'], 'text'];
        yield 'long text' => [['author_name' => 'A', 'text' => str_repeat('x', 2001)], 'text'];
        yield 'rating 0' => [['author_name' => 'A', 'text' => 'T', 'rating' => 0], 'rating'];
        yield 'rating 6' => [['author_name' => 'A', 'text' => 'T', 'rating' => '6'], 'rating'];
        yield 'rating float' => [['author_name' => 'A', 'text' => 'T', 'rating' => 4.5], 'rating'];
        yield 'gender' => [['author_name' => 'A', 'text' => 'T', 'gender' => 'other'], 'gender'];
        yield 'url scheme' => [['author_name' => 'A', 'text' => 'T', 'url' => 'ftp://x.y'], 'url'];
        yield 'url junk' => [['author_name' => 'A', 'text' => 'T', 'url' => 'not a url'], 'url'];
        yield 'url long' => [['author_name' => 'A', 'text' => 'T', 'url' => 'https://x.y/' . str_repeat('a', 510)], 'url'];
        yield 'is_active' => [['author_name' => 'A', 'text' => 'T', 'is_active' => 'maybe'], 'is_active'];
        yield 'sort_order negative' => [['author_name' => 'A', 'text' => 'T', 'sort_order' => -1], 'sort_order'];
        yield 'sort_order text' => [['author_name' => 'A', 'text' => 'T', 'sort_order' => 'first'], 'sort_order'];
    }

    /**
     * @dataProvider badInputs
     * @param array<string,mixed> $input
     */
    public function testRejects(array $input, string $field): void
    {
        try {
            $this->v->validate($input);
            self::fail("expected ValidationException on $field");
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->getFields());
        }
    }

    public function testMultipleErrorsAreReportedTogether(): void
    {
        try {
            $this->v->validate(['rating' => 9, 'gender' => 'x']);
            self::fail();
        } catch (ValidationException $e) {
            self::assertSame(['author_name', 'text', 'rating', 'gender'], array_keys($e->getFields()));
        }
    }

    public function testUnicodeLengthIsCountedInCharacters(): void
    {
        $text = str_repeat('Ж', 2000);
        self::assertSame($text, $this->v->validate(['author_name' => 'Đorđe', 'text' => $text])['text']);
    }
}
