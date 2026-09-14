<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $r = new Request('GET', '/api/x', headers: ['X-Requested-With' => 'XMLHttpRequest']);
        self::assertSame('XMLHttpRequest', $r->header('x-requested-with'));
        self::assertTrue($r->isXhr());
        self::assertTrue($r->isApi());
    }

    public function testQueryAndInputDefaults(): void
    {
        $r = new Request('POST', '/api/x', query: ['page' => '2'], body: ['name' => 'a']);
        self::assertSame('2', $r->query('page'));
        self::assertSame(20, $r->query('per_page', 20));
        self::assertSame('a', $r->input('name'));
        self::assertNull($r->input('missing'));
    }

    public function testWithAttributesReturnsNewInstance(): void
    {
        $r = new Request('GET', '/api/testimonials/5');
        $r2 = $r->withAttributes(['id' => '5']);
        self::assertSame('5', $r2->attribute('id'));
        self::assertNotSame($r, $r2);
        $this->expectException(\OutOfBoundsException::class);
        $r->attribute('id');
    }

    public function testFromGlobalsParsesJsonBodyAndPath(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $_SERVER['REQUEST_URI'] = '/api/testimonials/7?x=1';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_GET = ['x' => '1'];
        Request::$rawBodyProvider = fn () => '{"text":"hi"}';
        $r = Request::fromGlobals();
        self::assertSame('PATCH', $r->method);
        self::assertSame('/api/testimonials/7', $r->path);
        self::assertSame('hi', $r->input('text'));
        self::assertSame('1', $r->query('x'));
        self::assertTrue($r->isXhr());
        Request::$rawBodyProvider = null;
    }
}
