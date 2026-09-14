#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * First-run installer: creates the schema and demo data if the database is empty, then makes sure
 * the seeded demo photos exist. Idempotent — used as the Fly.io release command and for LAMP installs.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Db\PdoFactory;

$config = require dirname(__DIR__) . '/config/config.php';
$pdo = PdoFactory::create($config['db']);

$hasUsers = $pdo->query("SHOW TABLES LIKE 'users'")->fetch() !== false;
if ($hasUsers) {
    echo "install: schema present, skipping schema/seed\n";
} else {
    foreach (['schema', 'seed'] as $file) {
        $sql = (string) file_get_contents(dirname(__DIR__) . "/database/$file.sql");
        foreach (preg_split('/;\s*\n/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement === '' || preg_match('/^(--.*\n?)+$/', $statement)) {
                continue;
            }
            $pdo->exec($statement);
        }
        echo "install: $file applied\n";
    }
}
passthru(PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/database/seed-images.php'), $code);
exit($code);
