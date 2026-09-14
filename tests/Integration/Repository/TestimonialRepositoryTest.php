<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\TestimonialRepository;
use Tests\Integration\DatabaseTestCase;

final class TestimonialRepositoryTest extends DatabaseTestCase
{
    private TestimonialRepository $repo;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new TestimonialRepository(self::$pdo);
        $p = self::insert('products', ['parent_sku' => 'abforge', 'title' => 'AbForge']);
        self::insert('landings', ['id' => 1, 'product_id' => $p, 'country' => 'EN', 'is_master' => 1, 'url' => 'u', 'last_synced_at' => '2026-01-01 00:00:00']);
        $this->userId = self::insert('users', ['username' => 'admin', 'password_hash' => 'x', 'display_name' => 'Admin']);
    }

    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function fields(array $over = []): array
    {
        return $over + ['author_name' => 'A', 'text' => 'T', 'rating' => null, 'gender' => 'unisex', 'url' => null, 'is_active' => true, 'sort_order' => null];
    }

    public function testInsertAssignsNextSortOrderAndAudit(): void
    {
        self::assertSame(0, $this->repo->nextSortOrder(1));
        $a = $this->repo->insert(1, $this->fields(), $this->userId);
        $b = $this->repo->insert(1, $this->fields(['rating' => 4]), null);
        $rowA = $this->repo->find($a);
        self::assertSame(0, $rowA['sort_order']);
        self::assertSame(1, $this->repo->find($b)['sort_order']);
        self::assertSame($this->userId, $rowA['created_by']);
        self::assertSame($this->userId, $rowA['updated_by']);
        self::assertNull($rowA['rating']);
        self::assertTrue($rowA['is_active']);
        self::assertSame(4, $this->repo->find($b)['rating']);
        self::assertSame(2, $this->repo->countByLanding(1));
    }

    public function testListOrdersBySortOrderThenId(): void
    {
        $a = $this->repo->insert(1, $this->fields(['sort_order' => 5]), null);
        $b = $this->repo->insert(1, $this->fields(['sort_order' => 1]), null);
        $c = $this->repo->insert(1, $this->fields(['sort_order' => 5]), null);
        self::assertSame([$b, $a, $c], array_column($this->repo->listByLanding(1), 'id'));
        self::assertSame(6, $this->repo->nextSortOrder(1));
    }

    public function testUpdateOnlyTouchesGivenFields(): void
    {
        $id = $this->repo->insert(1, $this->fields(['author_name' => 'Before', 'text' => 'keep']), null);
        $this->repo->update($id, ['author_name' => 'After', 'is_active' => false], $this->userId);
        $row = $this->repo->find($id);
        self::assertSame('After', $row['author_name']);
        self::assertSame('keep', $row['text']);
        self::assertFalse($row['is_active']);
        self::assertSame($this->userId, $row['updated_by']);
        self::assertNull($row['created_by']);
        $this->repo->update($id, [], null);   // no-op must not throw
    }

    public function testUpdateRejectsUnknownColumn(): void
    {
        $id = $this->repo->insert(1, $this->fields(), null);
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->update($id, ['landing_id' => 999], null);
    }

    public function testDelete(): void
    {
        $id = $this->repo->insert(1, $this->fields(), null);
        self::assertTrue($this->repo->delete($id));
        self::assertFalse($this->repo->delete($id));
        self::assertNull($this->repo->find($id));
    }
}
