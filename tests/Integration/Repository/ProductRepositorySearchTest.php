<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\ProductRepository;
use Tests\Integration\DatabaseTestCase;

final class ProductRepositorySearchTest extends DatabaseTestCase
{
    private ProductRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ProductRepository(self::$pdo);
        $a = self::insert('products', ['parent_sku' => 'abforge', 'title' => 'Strengthen your abs', 'description' => 'core workouts', 'image' => 'a.webp']);
        $b = self::insert('products', ['parent_sku' => 'drivewaypro', 'title' => 'Clean driveway', 'description' => 'pressure washer', 'image' => null]);
        $c = self::insert('products', ['parent_sku' => 'zenmat', 'title' => 'Yoga mat', 'description' => 'abs and core', 'image' => null]);
        self::insert('landings', ['id' => 1, 'product_id' => $a, 'country' => 'EN', 'is_master' => 1, 'url' => 'u', 'last_synced_at' => '2026-01-01 00:00:00']);
        self::insert('landings', ['id' => 2, 'product_id' => $a, 'country' => 'SI', 'is_master' => 0, 'url' => 'u', 'last_synced_at' => '2026-01-01 00:00:00']);
        self::insert('landings', ['id' => 3, 'product_id' => $a, 'country' => 'IT', 'is_master' => 0, 'url' => 'u', 'last_synced_at' => '2026-01-01 00:00:00', 'removed_at' => '2026-01-02 00:00:00']);
        self::insert('landings', ['id' => 4, 'product_id' => $b, 'country' => 'EN', 'is_master' => 1, 'url' => 'u', 'last_synced_at' => '2026-01-01 00:00:00']);
        foreach ([1, 1, 2] as $landingId) {
            self::insert('testimonials', ['landing_id' => $landingId, 'author_name' => 'A', 'text' => 'T', 'rating' => 5, 'gender' => 'unisex', 'sort_order' => 0]);
        }
        self::insert('testimonials', ['landing_id' => 3, 'author_name' => 'A', 'text' => 'on removed landing', 'rating' => 5, 'gender' => 'unisex', 'sort_order' => 0]);
    }

    public function testCountsExcludeRemovedLandings(): void
    {
        $rows = $this->repo->search('', 'p.parent_sku', 'ASC', 10, 0);
        self::assertSame(['abforge', 'drivewaypro', 'zenmat'], array_column($rows, 'parent_sku'));
        self::assertSame(2, $rows[0]['landing_count']);
        self::assertSame(3, $rows[0]['testimonial_count']);
        self::assertSame(1, $rows[1]['landing_count']);
        self::assertSame(0, $rows[1]['testimonial_count']);
        self::assertSame(0, $rows[2]['landing_count']);
    }

    public function testSearchMatchesSkuTitleAndDescription(): void
    {
        self::assertSame(['abforge', 'zenmat'], array_column($this->repo->search('abs', 'p.parent_sku', 'ASC', 10, 0), 'parent_sku'));
        self::assertSame(['drivewaypro'], array_column($this->repo->search('DRIVEWAY', 'p.parent_sku', 'ASC', 10, 0), 'parent_sku'));
        self::assertSame(2, $this->repo->countSearch('abs'));
        self::assertSame(3, $this->repo->countSearch(''));
    }

    public function testSortByTestimonialsDescAndPaging(): void
    {
        $rows = $this->repo->search('', 'testimonial_count', 'DESC', 2, 0);
        self::assertSame(['abforge', 'drivewaypro'], array_column($rows, 'parent_sku'));
        $rows = $this->repo->search('', 'testimonial_count', 'DESC', 2, 2);
        self::assertSame(['zenmat'], array_column($rows, 'parent_sku'));
    }

    public function testLikeWildcardsInSearchAreLiteral(): void
    {
        self::assertSame([], $this->repo->search('%', 'p.parent_sku', 'ASC', 10, 0));
        self::assertSame([], $this->repo->search('_', 'p.parent_sku', 'ASC', 10, 0));
    }
}
