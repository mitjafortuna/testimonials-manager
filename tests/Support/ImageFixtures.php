<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Generates throw-away image files with GD so no binary fixtures need to live in the repo.
 */
final class ImageFixtures
{
    public static function png(string $dir, int $w = 640, int $h = 480): string
    {
        $path = tempnam($dir, 'img') . '.png';
        imagepng(self::canvas($w, $h), $path);
        return $path;
    }

    public static function jpeg(string $dir, int $w = 640, int $h = 480): string
    {
        $path = tempnam($dir, 'img') . '.jpg';
        imagejpeg(self::canvas($w, $h), $path, 85);
        return $path;
    }

    public static function webp(string $dir, int $w = 640, int $h = 480): string
    {
        $path = tempnam($dir, 'img') . '.webp';
        imagewebp(self::canvas($w, $h), $path, 80);
        return $path;
    }

    public static function text(string $dir): string
    {
        $path = tempnam($dir, 'txt') . '.jpg';   // wrong extension on purpose
        file_put_contents($path, "not an image\n");
        return $path;
    }

    /** @return array{name:string,type:string,tmp_name:string,error:int,size:int} */
    public static function asUpload(string $path, string $name = 'photo.jpg', int $error = UPLOAD_ERR_OK): array
    {
        return ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $path, 'error' => $error, 'size' => (int) filesize($path)];
    }

    private static function canvas(int $w, int $h): \GdImage
    {
        $im = imagecreatetruecolor($w, $h);
        $bg = (int) imagecolorallocate($im, 0, 96, 184);
        $fg = (int) imagecolorallocate($im, 255, 215, 33);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);
        imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), intdiv($w, 2), intdiv($h, 2), $fg);
        return $im;
    }
}
