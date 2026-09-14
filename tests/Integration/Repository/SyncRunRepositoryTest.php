<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\SyncRunRepository;
use Tests\Integration\DatabaseTestCase;

final class SyncRunRepositoryTest extends DatabaseTestCase
{
    public function testStartFinishLast(): void
    {
        $repo = new SyncRunRepository(self::$pdo);
        self::assertNull($repo->last());
        $id = $repo->start('2026-09-13 10:00:00');
        $repo->finish($id, 'ok', '2026-09-13 10:00:02', 3, 2, 1, null);
        $last = $repo->last();
        self::assertSame($id, $last['id']);
        self::assertSame('ok', $last['status']);
        self::assertSame(3, $last['added']);
        self::assertSame('2026-09-13 10:00:02', $last['finished_at']);
    }
}
