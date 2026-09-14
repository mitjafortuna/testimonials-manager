<?php

declare(strict_types=1);

/**
 * Generates placeholder image files for seeded testimonial_images rows whose files are missing.
 * Usage: php database/seed-images.php   (idempotent; safe to re-run)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Db\PdoFactory;

$config = require __DIR__ . '/../config/config.php';
$pdo = PdoFactory::create($config['db']);
$dir = rtrim((string) $config['upload']['dir'], '/');
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create $dir\n");
    exit(1);
}

$rows = $pdo->query('SELECT i.filename, i.thumb_filename, t.author_name FROM testimonial_images i JOIN testimonials t ON t.id = i.testimonial_id')->fetchAll();
$written = 0;
foreach ($rows as $row) {
    $full = "$dir/{$row['filename']}";
    $thumb = "$dir/{$row['thumb_filename']}";
    if (is_file($full) && is_file($thumb)) {
        continue;
    }
    $initial = mb_strtoupper(mb_substr(trim((string) $row['author_name']), 0, 1)) ?: '?';
    foreach ([[$full, 640, 480, 5], [$thumb, 300, 225, 3]] as [$path, $w, $h, $font]) {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 0, 96, 184));
        imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), intdiv($h, 2), intdiv($h, 2), imagecolorallocate($im, 255, 215, 33));
        $tw = imagefontwidth($font) * strlen($initial);
        imagestring($im, $font, intdiv($w - $tw, 2), intdiv($h - imagefontheight($font), 2), $initial, imagecolorallocate($im, 40, 40, 53));
        imagejpeg($im, $path, 82);
    }
    $written++;
}
echo "seed-images: wrote $written of " . count($rows) . " image pairs into $dir\n";
