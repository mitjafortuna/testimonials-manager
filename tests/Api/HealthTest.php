<?php

declare(strict_types=1);

namespace Tests\Api;

final class HealthTest extends ApiTestCase
{
    public function testHealthReportsDb(): void
    {
        $r = $this->request('GET', '/api/health');
        self::assertSame(200, $r['status']);
        self::assertSame(['status' => 'ok', 'db' => true], $r['json']);
        self::assertStringStartsWith('application/json', $r['headers']['content-type']);
    }

    public function testUnknownApiRouteIsJson404(): void
    {
        $r = $this->request('GET', '/api/does-not-exist');
        self::assertSame(404, $r['status']);
        self::assertSame('not_found', $r['json']['error']['code']);
    }

    public function testMutationWithoutXhrHeaderIs403(): void
    {
        $r = $this->request('POST', '/api/landings/sync', [], [], xhr: false);
        self::assertSame(403, $r['status']);
    }

    public function testRootServesSpaShell(): void
    {
        $ch = curl_init($this->baseUrl() . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $html = (string) curl_exec($ch);
        self::assertSame(200, curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
        self::assertStringContainsString('Testimonials Manager', $html);
    }
}
