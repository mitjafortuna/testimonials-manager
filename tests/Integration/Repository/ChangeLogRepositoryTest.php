<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\ChangeLogRepository;
use Tests\Integration\DatabaseTestCase;

final class ChangeLogRepositoryTest extends DatabaseTestCase
{
    public function testRecordAndListForEntityNewestFirstWithUserJoin(): void
    {
        $userId = self::insert('users', ['username' => 'admin', 'password_hash' => 'x', 'display_name' => 'Admin']);
        $repo = new ChangeLogRepository(self::$pdo);

        $repo->record('testimonial', 42, 'created', ['author_name' => 'A'], $userId);
        $repo->record('testimonial', 42, 'updated', ['author_name' => ['old' => 'A', 'new' => 'B']], $userId);
        $repo->record('testimonial', 42, 'deleted', null, null);
        $repo->record('testimonial', 99, 'created', ['author_name' => 'Other'], $userId);   // different entity

        $rows = $repo->listForEntity('testimonial', 42);
        self::assertCount(3, $rows);
        self::assertSame(['deleted', 'updated', 'created'], array_column($rows, 'action'));
        // assertEquals, not assertSame: MySQL's JSON column type does not preserve object key
        // insertion order, so 'old'/'new' may round-trip in a different order.
        self::assertEquals(['author_name' => ['old' => 'A', 'new' => 'B']], $rows[1]['changes']);
        self::assertNull($rows[0]['changes']);
        self::assertSame('Admin', $rows[1]['user_name']);
        self::assertNull($rows[0]['user_name']);   // user_id was null
    }

    public function testLimit(): void
    {
        $repo = new ChangeLogRepository(self::$pdo);
        for ($i = 0; $i < 5; $i++) {
            $repo->record('testimonial', 1, 'updated', null, null);
        }
        self::assertCount(2, $repo->listForEntity('testimonial', 1, 2));
    }
}
