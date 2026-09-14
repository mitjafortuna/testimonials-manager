<?php

declare(strict_types=1);

namespace Tests\Integration;

final class SchemaTest extends DatabaseTestCase
{
    private const TABLES = ['users', 'products', 'landings', 'testimonials', 'testimonial_images', 'change_log', 'sync_runs'];

    public function testAllTablesExistWithUtf8mb4Unicode(): void
    {
        $stmt = self::$pdo->prepare('SELECT TABLE_NAME, TABLE_COLLATION, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[$r['TABLE_NAME']] = $r;
        }
        foreach (self::TABLES as $t) {
            self::assertArrayHasKey($t, $rows, "table $t missing");
            self::assertSame('utf8mb4_unicode_ci', $rows[$t]['TABLE_COLLATION'], $t);
            self::assertSame('InnoDB', $rows[$t]['ENGINE'], $t);
        }
    }

    public function testSchemaIsIdempotent(): void
    {
        self::loadSql(dirname(__DIR__, 2) . '/database/schema.sql');
        self::assertTrue(true);
    }

    public function testLandingsProductCountryIsIndexedButNotUnique(): void
    {
        $stmt = self::$pdo->query("SHOW INDEX FROM landings WHERE Key_name = 'ix_landings_product_country'");
        $rows = $stmt->fetchAll();
        self::assertNotEmpty($rows, 'expected a non-unique index ix_landings_product_country');
        self::assertSame('1', (string) $rows[0]['Non_unique']);

        $unique = self::$pdo->query("SHOW INDEX FROM landings WHERE Key_name = 'uq_landings_product_country'")->fetchAll();
        self::assertSame([], $unique, 'the old unique key must be gone');
    }

    public function testTwoActiveLandingsCanShareProductAndCountry(): void
    {
        $productId = self::insert('products', ['parent_sku' => 'abforge', 'title' => 'AbForge']);
        self::insert('landings', ['id' => 1001, 'product_id' => $productId, 'country' => 'EN', 'is_master' => 1, 'url' => 'https://x/en/1', 'title' => 't', 'last_synced_at' => '2026-09-13 00:00:00']);
        self::insert('landings', ['id' => 1002, 'product_id' => $productId, 'country' => 'EN', 'is_master' => 0, 'url' => 'https://x/en/2', 'title' => 't', 'last_synced_at' => '2026-09-13 00:00:00']);
        self::assertSame(2, (int) self::$pdo->query('SELECT COUNT(*) FROM landings WHERE product_id = ' . $productId . " AND country = 'EN'")->fetchColumn());
    }

    public function testDeletingTestimonialCascadesToImages(): void
    {
        [$landingId, $testimonialId] = $this->seedLandingAndTestimonial();
        self::insert('testimonial_images', ['testimonial_id' => $testimonialId, 'filename' => 'a.jpg', 'thumb_filename' => 'a_thumb.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 10, 'width' => 1, 'height' => 1, 'sort_order' => 0]);
        self::$pdo->exec("DELETE FROM testimonials WHERE id = $testimonialId");
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM testimonial_images')->fetchColumn());
    }

    public function testDeletingLandingWithTestimonialsIsRestricted(): void
    {
        [$landingId] = $this->seedLandingAndTestimonial();
        try {
            self::$pdo->exec("DELETE FROM landings WHERE id = $landingId");
            self::fail('expected deleting a landing with testimonials to be restricted by a foreign key');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->errorInfo[0] ?? null);
            self::assertStringContainsString('fk_testimonials_landing', $e->getMessage());
        }
    }

    public function testRatingOutsideRangeIsRejected(): void
    {
        [$landingId] = $this->seedLandingAndTestimonial();
        try {
            self::insert('testimonials', ['landing_id' => $landingId, 'author_name' => 'x', 'text' => 'y', 'rating' => 6, 'gender' => 'unisex', 'sort_order' => 1]);
            self::fail('expected rating outside 1-5 to be rejected by the CHECK constraint');
        } catch (\PDOException $e) {
            self::assertStringContainsString('chk_testimonials_rating', $e->getMessage());
        }
    }

    public function testStoresCyrillicGreekTurkish(): void
    {
        [$landingId] = $this->seedLandingAndTestimonial();
        $text = 'Отлично · Εξαιρετικό · Mükemmel · Čšž';
        $id = self::insert('testimonials', ['landing_id' => $landingId, 'author_name' => 'Đorđe', 'text' => $text, 'rating' => 5, 'gender' => 'male', 'sort_order' => 1]);
        self::assertSame($text, self::$pdo->query("SELECT text FROM testimonials WHERE id = $id")->fetchColumn());
    }

    /** @return array{0:int,1:int} */
    private function seedLandingAndTestimonial(): array
    {
        $productId = self::insert('products', ['parent_sku' => 'abforge', 'title' => 'AbForge', 'description' => 'd', 'image' => 'i']);
        self::insert('landings', ['id' => 61763, 'product_id' => $productId, 'country' => 'EN', 'is_master' => 1, 'url' => 'https://x/en', 'title' => 't', 'description' => 'd', 'image' => 'i', 'status' => 'READY', 'last_synced_at' => '2026-09-13 00:00:00']);
        $testimonialId = self::insert('testimonials', ['landing_id' => 61763, 'author_name' => 'A', 'text' => 'T', 'rating' => 5, 'gender' => 'female', 'sort_order' => 0]);
        return [61763, $testimonialId];
    }
}
