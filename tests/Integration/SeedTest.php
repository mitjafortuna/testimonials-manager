<?php

declare(strict_types=1);

namespace Tests\Integration;

final class SeedTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::loadSql(dirname(__DIR__, 2) . '/database/seed.sql');
    }

    public function testSeedContainsAdminUserWithHashedPassword(): void
    {
        $hash = self::$pdo->query("SELECT password_hash FROM users WHERE username = 'admin'")->fetchColumn();
        self::assertIsString($hash);
        self::assertTrue(password_verify('admin123', $hash));
    }

    public function testSeedContainsAllUpstreamLandings(): void
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/tests/fixtures/landings.json'), true);
        self::assertSame(count($fixture['data']), (int) self::$pdo->query('SELECT COUNT(*) FROM landings')->fetchColumn());
        self::assertSame($fixture['meta']['products'], (int) self::$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
        self::assertSame($fixture['meta']['products'], (int) self::$pdo->query('SELECT COUNT(*) FROM landings WHERE is_master = 1')->fetchColumn());
    }

    public function testSeedHasTestimonialsWithImagesOnSomeLandings(): void
    {
        self::assertGreaterThan(20, (int) self::$pdo->query('SELECT COUNT(*) FROM testimonials')->fetchColumn());
        self::assertGreaterThan(0, (int) self::$pdo->query('SELECT COUNT(*) FROM testimonials WHERE rating IS NULL')->fetchColumn(), 'some random ratings');
        self::assertGreaterThan(0, (int) self::$pdo->query('SELECT COUNT(DISTINCT landing_id) FROM testimonials t JOIN landings l ON l.id = t.landing_id WHERE l.is_master = 0')->fetchColumn(), 'some localised landings have own testimonials');
    }

    public function testSeedIsIdempotent(): void
    {
        self::loadSql(dirname(__DIR__, 2) . '/database/seed.sql');
        self::assertSame(1, (int) self::$pdo->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn());
    }
}
