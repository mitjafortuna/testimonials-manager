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

    public function testFromGlobalsStripsSubFolderBasePath(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/sub/public/index.php';
        $_SERVER['REQUEST_URI'] = '/sub/public/api/x?y=1';
        $_GET = ['y' => '1'];
        $r = Request::fromGlobals();
        self::assertSame('/api/x', $r->path);
    }

    public function testFromGlobalsWithNoBasePathIsUnchanged(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/api/x';
        unset($_GET['y']);
        $r = Request::fromGlobals();
        self::assertSame('/api/x', $r->path);
    }

    public function testFromGlobalsStripsSubFolderBasePathWhenApacheRewriteDropsPublic(): void
    {
        // Root .htaccess rewrites /tm/api/x internally to public/api/x, so PHP sees
        // SCRIPT_NAME=/tm/public/index.php but the ORIGINAL REQUEST_URI=/tm/api/x (no /public).
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/tm/public/index.php';
        $_SERVER['REQUEST_URI'] = '/tm/api/x';
        $r = Request::fromGlobals();
        self::assertSame('/api/x', $r->path);
    }

    public function testFromGlobalsStripsSubFolderBasePathWhenRequestUriIncludesPublic(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/tm/public/index.php';
        $_SERVER['REQUEST_URI'] = '/tm/public/api/x';
        $r = Request::fromGlobals();
        self::assertSame('/api/x', $r->path);
    }

    public function testFromGlobalsDoesNotStripALongerSiblingPath(): void
    {
        // "/sub" must only match on a segment boundary — it must not strip the common
        // prefix off an unrelated path like "/subway/...".
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/sub/index.php';
        $_SERVER['REQUEST_URI'] = '/subway/api/x';
        $r = Request::fromGlobals();
        self::assertSame('/subway/api/x', $r->path);
    }

    public function testFromGlobalsIgnoresAScriptNameThatIsNotAPhpFrontController(): void
    {
        // The PHP built-in server sets SCRIPT_NAME to the request path whenever the last segment
        // looks like a file, so /media/<uuid>.png would otherwise have "/media" stripped off it.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/media/ffffffff-ffff-4fff-8fff-ffffffffffff_thumb.png';
        $_SERVER['REQUEST_URI'] = '/media/ffffffff-ffff-4fff-8fff-ffffffffffff_thumb.png';
        $r = Request::fromGlobals();
        self::assertSame('/media/ffffffff-ffff-4fff-8fff-ffffffffffff_thumb.png', $r->path);
    }

    public function testFromGlobalsStripsSubFolderBaseDownToRoot(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/tm/public/index.php';
        $_SERVER['REQUEST_URI'] = '/tm/';
        $r = Request::fromGlobals();
        self::assertSame('/', $r->path);
    }
}
