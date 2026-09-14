<?php

declare(strict_types=1);

namespace Tests\Integration\Application;

use App\Application\LandingOverviewService;
use App\Infrastructure\Repository\LandingRepository;
use Tests\Integration\DatabaseTestCase;

final class LandingOverviewServiceTest extends DatabaseTestCase
{
    private function service(): LandingOverviewService
    {
        return new LandingOverviewService(new LandingRepository(self::$pdo));
    }

    public function testNoInheritanceWhenMasterIsRemoved(): void
    {
        $p = self::insert('products', ['parent_sku' => 'removedmaster', 'title' => 'RM']);
        self::insert('landings', [
            'id' => 20, 'product_id' => $p, 'country' => 'EN', 'is_master' => 1, 'url' => 'u/en',
            'last_synced_at' => '2026-01-01 00:00:00', 'removed_at' => '2026-01-02 00:00:00',
        ]);
        self::insert('landings', ['id' => 21, 'product_id' => $p, 'country' => 'SI', 'is_master' => 0, 'url' => 'u/si', 'last_synced_at' => '2026-01-01 00:00:00']);
        self::insert('landings', ['id' => 22, 'product_id' => $p, 'country' => 'DE', 'is_master' => 0, 'url' => 'u/de', 'last_synced_at' => '2026-01-01 00:00:00']);
        self::insert('testimonials', ['landing_id' => 22, 'author_name' => 'A', 'text' => 'T', 'gender' => 'unisex', 'sort_order' => 0]);

        $data = $this->service()->forProduct('removedmaster');
        $bySi = current(array_filter($data['data'], fn ($r) => $r['id'] === 21));

        self::assertFalse($bySi['inherits_from_master']);
        self::assertSame(0, $bySi['inherited_count']);
    }

    public function testInheritsFromActiveMaster(): void
    {
        $p = self::insert('products', ['parent_sku' => 'activemaster', 'title' => 'AM']);
        self::insert('landings', ['id' => 30, 'product_id' => $p, 'country' => 'EN', 'is_master' => 1, 'url' => 'u/en', 'last_synced_at' => '2026-01-01 00:00:00']);
        self::insert('landings', ['id' => 31, 'product_id' => $p, 'country' => 'SI', 'is_master' => 0, 'url' => 'u/si', 'last_synced_at' => '2026-01-01 00:00:00']);
        foreach ([30, 30] as $l) {
            self::insert('testimonials', ['landing_id' => $l, 'author_name' => 'A', 'text' => 'T', 'gender' => 'unisex', 'sort_order' => 0]);
        }

        $data = $this->service()->forProduct('activemaster');
        $master = current(array_filter($data['data'], fn ($r) => $r['id'] === 30));
        $si = current(array_filter($data['data'], fn ($r) => $r['id'] === 31));

        self::assertFalse($master['inherits_from_master']);
        self::assertSame(0, $master['inherited_count']);
        self::assertTrue($si['inherits_from_master']);
        self::assertSame(2, $si['inherited_count']);
    }
}
