<?php

declare(strict_types=1);

/**
 * Inserts a large synthetic dataset for performance checks:
 * ~300 products × up to 20 countries × up to 50 testimonials (~200k rows).
 * Usage: php database/seed-large.php [products=300] [countries=20] [testimonials=50]
 * Uses landing ids from 1_000_000 upwards so it never collides with upstream ids.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Db\PdoFactory;
use App\Support\Env;

/** @var list<string> $argv */
Env::load(__DIR__ . '/../.env');
$products = (int) ($argv[1] ?? 300);
$countries = (int) ($argv[2] ?? 20);
$perLanding = (int) ($argv[3] ?? 50);
$countryCodes = array_slice(['EN', 'SI', 'IT', 'DE', 'AT', 'HR', 'HU', 'CZ', 'SK', 'PL', 'RO', 'BG', 'GR', 'TR', 'FR', 'ES', 'PT', 'NL', 'BE', 'DK'], 0, $countries);

$pdo = PdoFactory::create([
    'host' => Env::get('DB_HOST', '127.0.0.1'), 'port' => (int) Env::get('DB_PORT', '3306'),
    'name' => Env::get('DB_NAME', 'testimonials'), 'user' => Env::get('DB_USER', 'app'), 'pass' => Env::get('DB_PASS', 'app'),
]);
$pdo->beginTransaction();
$insProduct = $pdo->prepare('INSERT INTO products (parent_sku, title, description, image) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
$insLanding = $pdo->prepare('INSERT IGNORE INTO landings (id, product_id, country, is_master, url, title, description, image, status, last_synced_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
$landingId = 1_000_000;
/** @var list<mixed> $batch */
$batch = [];
$flush = static function () use (&$batch, $pdo): void {
    if ($batch === []) {
        return;
    }
    $sql = 'INSERT INTO testimonials (landing_id, author_name, text, rating, gender, url, is_active, sort_order) VALUES ' . implode(',', array_fill(0, (int) (count($batch) / 8), '(?,?,?,?,?,?,?,?)'));
    $pdo->prepare($sql)->execute($batch);
    $batch = [];
};
for ($p = 1; $p <= $products; $p++) {
    $sku = sprintf('demo-product-%03d', $p);
    $insProduct->execute([$sku, "Demo product $p", "Synthetic description for product $p", null]);
    $productId = (int) $pdo->lastInsertId();
    foreach ($countryCodes as $i => $cc) {
        $landingId++;
        $insLanding->execute([$landingId, $productId, $cc, $cc === 'EN' ? 1 : 0, "https://example.com/$cc/$sku", "Demo product $p ($cc)", null, null, 'DEMO']);
        $n = mt_rand(0, $perLanding);
        for ($t = 0; $t < $n; $t++) {
            array_push($batch, $landingId, "Author $t", "Synthetic testimonial $t for $sku in $cc.", mt_rand(0, 4) === 0 ? null : mt_rand(3, 5), ['male', 'female', 'unisex'][$t % 3], null, 1, $t);
            if (count($batch) >= 8 * 500) {
                $flush();
            }
        }
    }
    if ($p % 25 === 0) {
        fwrite(STDERR, "products: $p\n");
    }
}
$flush();
$pdo->commit();
echo "Inserted $products products, up to $countries countries each, up to $perLanding testimonials per landing.\n";
