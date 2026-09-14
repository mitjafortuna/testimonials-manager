<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Upstream;

use App\Infrastructure\Upstream\FixtureLandingsApiClient;
use PHPUnit\Framework\TestCase;

final class FixtureLandingsApiClientTest extends TestCase
{
    public function testLoadsCapturedFixture(): void
    {
        $client = FixtureLandingsApiClient::fromFile(dirname(__DIR__, 4) . '/tests/fixtures/landings.json');
        $rows = $client->fetchAll();
        self::assertCount(170, $rows);
        self::assertSame(10, count(array_filter($rows, fn ($r) => $r['is_master'])));
        self::assertSame(['id', 'parent_sku', 'country', 'is_master', 'url', 'title', 'description', 'image', 'status'], array_keys($rows[0]));
    }
}
