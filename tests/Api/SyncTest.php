<?php

declare(strict_types=1);

namespace Tests\Api;

final class SyncTest extends ApiTestCase
{
    public function testSyncRunsAndReportsCounts(): void
    {
        $r = $this->request('POST', '/api/landings/sync');
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertArrayHasKey('added', $r['json']['run']);
        self::assertSame(0, $r['json']['run']['removed']);
        self::assertIsInt($r['json']['run']['duration_ms']);
        self::assertGreaterThanOrEqual(0, $r['json']['run']['duration_ms']);

        $last = $this->request('GET', '/api/sync/last');
        self::assertSame(200, $last['status']);
        self::assertSame('ok', $last['json']['run']['status']);
    }
}
