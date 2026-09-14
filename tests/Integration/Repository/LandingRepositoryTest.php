<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\LandingRepository;
use App\Infrastructure\Repository\ProductRepository;
use Tests\Integration\DatabaseTestCase;

final class LandingRepositoryTest extends DatabaseTestCase
{
    private LandingRepository $landings;
    private ProductRepository $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->landings = new LandingRepository(self::$pdo);
        $this->products = new ProductRepository(self::$pdo);
        $this->products->upsertMany([['parent_sku' => 'abforge', 'title' => 'AbForge', 'description' => null, 'image' => null]]);
    }

    /** @return array<string,mixed> */
    private function row(int $id, string $cc, string $url = 'https://x'): array
    {
        return ['id' => $id, 'product_id' => $this->products->idsBySku()['abforge'], 'country' => $cc, 'is_master' => $cc === 'EN', 'url' => $url, 'title' => 't', 'description' => null, 'image' => null, 'status' => null];
    }

    public function testUpsertCountsAddedUpdatedUnchanged(): void
    {
        $r1 = $this->landings->upsertMany([$this->row(1, 'EN'), $this->row(2, 'SI')], '2026-09-13 10:00:00');
        self::assertSame(['added' => 2, 'updated' => 0], $r1);
        $r2 = $this->landings->upsertMany([$this->row(1, 'EN'), $this->row(2, 'SI', 'https://changed')], '2026-09-13 11:00:00');
        self::assertSame(['added' => 0, 'updated' => 1], $r2);
        self::assertSame('https://changed', $this->landings->find(2)['url']);
        $r3 = $this->landings->upsertMany([$this->row(1, 'EN'), $this->row(2, 'SI', 'https://changed')], '2026-09-13 12:00:00');
        self::assertSame(['added' => 0, 'updated' => 0], $r3);
    }

    public function testMarkRemovedExceptAndReappear(): void
    {
        $this->landings->upsertMany([$this->row(1, 'EN'), $this->row(2, 'SI')], '2026-09-13 10:00:00');
        self::assertSame(1, $this->landings->markRemovedExcept([1], '2026-09-13 10:00:00'));
        self::assertNotNull($this->landings->find(2)['removed_at']);
        $this->landings->upsertMany([$this->row(2, 'SI')], '2026-09-13 12:00:00');
        self::assertNull($this->landings->find(2)['removed_at']);
    }

    public function testReappearingRemovedLandingCountsAsUpdated(): void
    {
        $this->landings->upsertMany([$this->row(1, 'EN'), $this->row(2, 'SI')], '2026-09-13 10:00:00');
        $this->landings->markRemovedExcept([1], '2026-09-13 10:00:00');
        $result = $this->landings->upsertMany([$this->row(2, 'SI')], '2026-09-13 12:00:00');
        self::assertSame(['added' => 0, 'updated' => 1], $result);
        self::assertNull($this->landings->find(2)['removed_at']);
    }

    public function testProductIdsBySku(): void
    {
        $this->products->upsertMany([['parent_sku' => 'zeta', 'title' => 'Z', 'description' => 'd', 'image' => 'i'], ['parent_sku' => 'abforge', 'title' => 'AbForge v2', 'description' => null, 'image' => null]]);
        $ids = $this->products->idsBySku();
        self::assertSame(['abforge', 'zeta'], array_keys($ids));
        self::assertSame('AbForge v2', self::$pdo->query("SELECT title FROM products WHERE parent_sku='abforge'")->fetchColumn());
        self::assertSame(2, (int) self::$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
    }
}
