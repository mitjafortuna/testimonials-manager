<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * Files live outside the web root under generated UUID names; thumbnails are made with GD.
 */
final class ImageStorage
{
    private const SAFE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}(_thumb)?\.(jpg|png|webp)$/';
    private const MIMES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function __construct(private readonly string $dir, private readonly int $thumbSize = 300)
    {
    }

    /** @return array{filename:string,thumb_filename:string} */
    public function store(string $sourcePath, string $ext): array
    {
        if (!isset(self::MIMES[$ext])) {
            throw new \InvalidArgumentException("Unsupported extension '$ext'");
        }
        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Upload directory '{$this->dir}' is not writable");
        }
        $uuid = self::uuid4();
        $filename = "$uuid.$ext";
        $thumb = "{$uuid}_thumb.$ext";
        $target = $this->path($filename);
        $moved = is_uploaded_file($sourcePath) ? move_uploaded_file($sourcePath, $target) : rename($sourcePath, $target);
        if (!$moved) {
            throw new \RuntimeException('Could not store uploaded file');
        }
        $this->writeThumbnail($target, $this->path($thumb), $ext);
        return ['filename' => $filename, 'thumb_filename' => $thumb];
    }

    public function delete(string $filename, string $thumbFilename): void
    {
        foreach ([$filename, $thumbFilename] as $f) {
            if (self::isSafeFilename($f) && is_file($this->path($f))) {
                @unlink($this->path($f));
            }
        }
    }

    public function path(string $filename): string
    {
        return rtrim($this->dir, '/') . '/' . $filename;
    }

    public static function isSafeFilename(string $filename): bool
    {
        return preg_match(self::SAFE, $filename) === 1;
    }

    public static function mimeFor(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return self::MIMES[$ext] ?? 'application/octet-stream';
    }

    /** @param 'jpg'|'png'|'webp' $ext */
    private function writeThumbnail(string $source, string $target, string $ext): void
    {
        $image = match ($ext) {
            'jpg' => imagecreatefromjpeg($source),
            'png' => imagecreatefrompng($source),
            'webp' => imagecreatefromwebp($source),
        };
        if ($image === false) {
            throw new \RuntimeException('Could not decode image');
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1.0, $this->thumbSize / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $thumb = imagescale($image, $tw, $th, IMG_BICUBIC);
        if ($thumb === false) {
            throw new \RuntimeException('Could not scale image');
        }
        if ($ext === 'png') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }
        $ok = match ($ext) {
            'jpg' => imagejpeg($thumb, $target, 85),
            'png' => imagepng($thumb, $target, 6),
            'webp' => imagewebp($thumb, $target, 82),
        };
        if (!$ok) {
            throw new \RuntimeException('Could not write thumbnail');
        }
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
