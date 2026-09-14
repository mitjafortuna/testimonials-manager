<?php

declare(strict_types=1);

namespace Tests\Api;

final class AuthTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->logout();   // this class tests the logged-out state explicitly
    }

    public function testProtectedEndpointsAre401WhenLoggedOut(): void
    {
        foreach (['/api/products', '/api/auth/me', '/api/sync/last'] as $path) {
            $r = $this->request('GET', $path);
            self::assertSame(401, $r['status'], $path);
            self::assertSame('unauthorized', $r['json']['error']['code']);
        }
        self::assertSame(200, $this->request('GET', '/api/health')['status']);
    }

    public function testLoginLogoutFlow(): void
    {
        $bad = $this->request('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'wrong']);
        self::assertSame(401, $bad['status']);
        self::assertSame('invalid_credentials', $bad['json']['error']['code']);

        $blank = $this->request('POST', '/api/auth/login', ['username' => '', 'password' => '']);
        self::assertSame(422, $blank['status']);
        self::assertSame(['username', 'password'], array_keys($blank['json']['error']['fields']));

        $ok = $this->request('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'admin123']);
        self::assertSame(200, $ok['status']);
        self::assertSame('admin', $ok['json']['user']['username']);
        self::assertArrayNotHasKey('password_hash', $ok['json']['user']);
        self::assertStringContainsString('HttpOnly', $ok['headers']['set-cookie'] ?? '');
        self::assertStringContainsString('SameSite=Lax', $ok['headers']['set-cookie'] ?? '');

        $me = $this->request('GET', '/api/auth/me');
        self::assertSame(200, $me['status']);
        self::assertSame('Demo Admin', $me['json']['user']['display_name']);
        self::assertSame(200, $this->request('GET', '/api/products')['status']);

        self::assertSame(204, $this->request('POST', '/api/auth/logout')['status']);
        self::assertSame(401, $this->request('GET', '/api/auth/me')['status']);
    }

    public function testLoginRequiresXhrHeader(): void
    {
        $r = $this->request('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'admin123'], [], xhr: false);
        self::assertSame(403, $r['status']);
    }
}
