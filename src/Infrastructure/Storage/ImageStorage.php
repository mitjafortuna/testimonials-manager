<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * Files live outside the web root under generated UUID names; the main image is downscaled to a
 * sane maximum and a thumbnail is made with GD. Conversion to WebP and square cropping are opt-in
 * per upload — the default call (2 args) behaves exactly as before, only larger main images shrink.
 */
final class ImageStorage
{
    private const SAFE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}(_thumb)?\.(jpg|png|webp)$/';
    private const MIMES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    private const MAX_MAIN = 1600;
    private const MAIN_QUALITY = ['jpg' => 88, 'png' => 6, 'webp' => 85];
    private const THUMB_QUALITY = ['jpg' => 85, 'png' => 6, 'webp' => 82];

    public function __construct(private readonly string $dir, private readonly int $thumbSize = 300)
    {
    }

    /** @return array{filename:string,thumb_filename:string,mime:string,width:int,height:int} */
    public function store(string $sourcePath, string $ext, ?string $crop = null, bool $convertWebp = false): array
    {
        if (!isset(self::MIMES[$ext])) {
            throw new \InvalidArgumentException("Unsupported extension '$ext'");
        }
        if ($crop !== null && $crop !== 'square') {
            throw new \InvalidArgumentException("Unsupported crop mode '$crop'");
        }
        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Upload directory '{$this->dir}' is not writable");
        }

        $image = $this->decode($sourcePath, $ext);
        @unlink($sourcePath);

        if ($crop === 'square') {
            $side = min(imagesx($image), imagesy($image));
            $cropped = imagecrop($image, [
                'x' => intdiv(imagesx($image) - $side, 2), 'y' => intdiv(imagesy($image) - $side, 2),
                'width' => $side, 'height' => $side,
            ]);
            if ($cropped !== false) {
                $image = $cropped;
            }
        }
        $image = $this->downscale($image, self::MAX_MAIN);

        $finalExt = $convertWebp ? 'webp' : $ext;
        $uuid = self::uuid4();
        $filename = "$uuid.$finalExt";
        $thumb = "{$uuid}_thumb.$finalExt";
        $this->writeImage($image, $this->path($filename), $finalExt, self::MAIN_QUALITY[$finalExt]);
        $this->writeImage($this->downscale($image, $this->thumbSize), $this->path($thumb), $finalExt, self::THUMB_QUALITY[$finalExt]);

        return [
            'filename' => $filename, 'thumb_filename' => $thumb, 'mime' => self::MIMES[$finalExt],
            'width' => imagesx($image), 'height' => imagesy($image),
        ];
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
    private function decode(string $path, string $ext): \GdImage
    {
        $image = match ($ext) {
            'jpg' => imagecreatefromjpeg($path),
            'png' => imagecreatefrompng($path),
            'webp' => imagecreatefromwebp($path),
        };
        if ($image === false) {
            throw new \RuntimeException('Could not decode image');
        }
        return $image;
    }

    /** Scales down so the longest side is at most $maxSide; never upscales. */
    private function downscale(\GdImage $image, int $maxSide): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1.0, $maxSide / max($w, $h));
        if ($scale >= 1.0) {
            return $image;
        }
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $scaled = imagescale($image, $tw, $th, IMG_BICUBIC);
        if ($scaled === false) {
            throw new \RuntimeException('Could not scale image');
        }
        return $scaled;
    }

    /** @param 'jpg'|'png'|'webp' $ext */
    private function writeImage(\GdImage $image, string $target, string $ext, int $quality): bool
    {
        if ($ext === 'png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        $ok = match ($ext) {
            'jpg' => imagejpeg($image, $target, $quality),
            'png' => imagepng($image, $target, $quality),
            'webp' => imagewebp($image, $target, $quality),
        };
        if (!$ok) {
            throw new \RuntimeException('Could not write image');
        }
        return $ok;
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
