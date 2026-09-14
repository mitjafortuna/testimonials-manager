<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\LandingRepository;
use Tests\Integration\DatabaseTestCase;

final class LandingRepositoryOverviewTest extends DatabaseTestCase
{
    private LandingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new LandingRepository(self::$pdo);
        $p = self::insert('products', ['parent_sku' => 'abforge', 'title' => 'AbForge']);
        self::insert('landings', ['id' => 10, 'product_id' => $p, 'country' => 'SI', 'is_master' => 0, 'url' => 'u/si', 'title' => 'SI title', 'status' => 'ADVERTISING', 'last_synced_at' => '2026-01-01 00:00:00']);
        self::insert('landings', ['id' => 11, 'product_id' => $p, 'country' => 'EN', 'is_master' => 1, 'url' => 'u/en', 'title' => 'EN title', 'last_synced_at' => '2026-01-01 00:00:00']);
        self::insert('landings', ['id' => 12, 'product_id' => $p, 'country' => 'DE', 'is_master' => 0, 'url' => 'u/de', 'last_synced_at' => '2026-01-01 00:00:00', 'removed_at' => '2026-01-02 00:00:00']);
        self::insert('landings', ['id' => 13, 'product_id' => $p, 'country' => 'BG', 'is_master' => 0, 'url' => 'u/bg', 'last_synced_at' => '2026-01-01 00:00:00']);
        foreach ([11, 11, 10] as $l) {
            self::insert('testimonials', ['landing_id' => $l, 'author_name' => 'A', 'text' => 'T', 'gender' => 'unisex', 'sort_order' => 0]);
        }
    }

    public function testListsActiveLandingsMasterFirstWithCounts(): void
    {
        $rows = $this->repo->listByProductSku('abforge');
        self::assertSame([11, 13, 10], array_column($rows, 'id'));
        self::assertTrue($rows[0]['is_master']);
        self::assertSame(2, $rows[0]['testimonial_count']);
        self::assertSame(0, $rows[1]['testimonial_count']);
        self::assertSame(1, $rows[2]['testimonial_count']);
        self::assertSame('ADVERTISING', $rows[2]['status']);
        self::assertSame([], $this->repo->listByProductSku('nope'));
    }

    public function testFindMasterFor(): void
    {
        self::assertSame(11, $this->repo->findMasterFor(10)['id']);
        self::assertSame(11, $this->repo->findMasterFor(11)['id']);
        self::assertNull($this->repo->findMasterFor(999));
    }
}
