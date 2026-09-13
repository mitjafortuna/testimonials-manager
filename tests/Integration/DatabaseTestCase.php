<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Db\PdoFactory;
use App\Support\Env;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected static \PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = PdoFactory::create([
            'host' => Env::get('DB_HOST', '127.0.0.1'),
            'port' => (int) Env::get('DB_PORT', '3306'),
            'name' => Env::get('TEST_DB_NAME', 'testimonials_test'),
            'user' => Env::get('DB_USER', 'app'),
            'pass' => Env::get('DB_PASS', 'app'),
        ]);
        self::loadSql(dirname(__DIR__, 2) . '/database/schema.sql');
    }

    protected function setUp(): void
    {
        self::truncateAll();
    }

    protected static function loadSql(string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read $file");
        }
        $statements = preg_split('/;\s*\n/', $sql);
        if ($statements === false) {
            throw new \RuntimeException("Cannot parse $file");
        }
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || preg_match('/^(--.*\n?)+$/', $statement) === 1) {
                continue;
            }
            self::$pdo->exec($statement);
        }
    }

    protected static function truncateAll(): void
    {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $tables = self::$pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            self::$pdo->exec("TRUNCATE TABLE `$table`");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /** @param array<string,mixed> $row */
    protected static function insert(string $table, array $row): int
    {
        $cols = implode(',', array_map(fn ($c) => "`$c`", array_keys($row)));
        $marks = implode(',', array_fill(0, count($row), '?'));
        self::$pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($marks)")->execute(array_values($row));
        return (int) self::$pdo->lastInsertId();
    }
}
