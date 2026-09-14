<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\Env;

final class InstallTest extends DatabaseTestCase
{
    public function testInstallScriptIsIdempotentAndSeeds(): void
    {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['testimonial_images', 'testimonials', 'change_log', 'sync_runs', 'landings', 'products', 'users'] as $t) {
            self::$pdo->exec("DROP TABLE IF EXISTS `$t`");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $uploads = sys_get_temp_dir() . '/tm-install-' . bin2hex(random_bytes(3));
        $env = sprintf('DB_NAME=%s UPLOAD_DIR=%s', escapeshellarg((string) Env::get('TEST_DB_NAME', 'testimonials_test')), escapeshellarg($uploads));
        $first = shell_exec("$env php " . dirname(__DIR__, 2) . '/bin/install.php 2>&1');
        self::assertStringContainsString('schema applied', (string) $first);
        self::assertStringContainsString('seed applied', (string) $first);
        self::assertSame(170, (int) self::$pdo->query('SELECT COUNT(*) FROM landings')->fetchColumn());
        self::assertGreaterThan(0, count(glob("$uploads/*.jpg") ?: []));
        $second = shell_exec("$env php " . dirname(__DIR__, 2) . '/bin/install.php 2>&1');
        self::assertStringContainsString('schema present', (string) $second);
        array_map('unlink', glob("$uploads/*") ?: []);
        @rmdir($uploads);
        self::loadSql(dirname(__DIR__, 2) . '/database/schema.sql');
    }
}
