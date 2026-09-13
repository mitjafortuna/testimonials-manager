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
        $this->expectException(\PDOException::class);
        self::$pdo->exec("DELETE FROM landings WHERE id = $landingId");
    }

    public function testRatingOutsideRangeIsRejected(): void
    {
        [$landingId] = $this->seedLandingAndTestimonial();
        $this->expectException(\PDOException::class);
        self::insert('testimonials', ['landing_id' => $landingId, 'author_name' => 'x', 'text' => 'y', 'rating' => 6, 'gender' => 'unisex', 'sort_order' => 1]);
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
