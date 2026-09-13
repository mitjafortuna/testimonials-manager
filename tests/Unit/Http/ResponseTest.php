<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testJson(): void
    {
        $r = Response::json(['a' => 'č'], 201);
        self::assertSame(201, $r->status);
        self::assertSame('{"a":"č"}', $r->body);
        self::assertSame('application/json; charset=utf-8', $r->headers['Content-Type']);
    }

    public function testErrorShape(): void
    {
        $r = Response::error(422, 'validation_failed', 'Validation failed', ['text' => 'Required']);
        self::assertSame(['error' => ['code' => 'validation_failed', 'message' => 'Validation failed', 'fields' => ['text' => 'Required']]], json_decode($r->body, true));
    }

    public function testNoContent(): void
    {
        self::assertSame(204, Response::noContent()->status);
        self::assertSame('', Response::noContent()->body);
    }

    public function testWithHeaderIsImmutable(): void
    {
        $a = Response::json([]);
        $b = $a->withHeader('X-Test', '1');
        self::assertArrayNotHasKey('X-Test', $a->headers);
        self::assertSame('1', $b->headers['X-Test']);
    }
}
