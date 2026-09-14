<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Image;

use App\Domain\Exception\HttpException;
use App\Domain\Exception\ValidationException;
use App\Domain\Image\ImageValidator;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImageFixtures;

final class ImageValidatorTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            @unlink($path);
        }
        $this->created = [];
    }

    public function testAcceptsJpegPngWebpAndSniffsMime(): void
    {
        $v = new ImageValidator(5_000_000);
        foreach ([['jpeg', 'image/jpeg', 'jpg'], ['png', 'image/png', 'png'], ['webp', 'image/webp', 'webp']] as [$fn, $mime, $ext]) {
            /** @var string $path */
            $path = ImageFixtures::$fn($this->dir);
            $r = $v->validate(ImageFixtures::asUpload($this->track($path), 'anything.bin'));
            self::assertSame($mime, $r['mime']);
            self::assertSame($ext, $r['ext']);
            self::assertSame([640, 480], [$r['width'], $r['height']]);
        }
    }

    public function testRejectsNonImageEvenWithImageExtension(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/JPG, PNG or WebP/');
        try {
            (new ImageValidator(5_000_000))->validate(ImageFixtures::asUpload($this->track(ImageFixtures::text($this->dir)), 'evil.jpg'));
        } catch (HttpException $e) {
            self::assertSame(415, $e->getStatus());
            throw $e;
        }
    }

    public function testRejectsOversize(): void
    {
        try {
            (new ImageValidator(1000))->validate(ImageFixtures::asUpload($this->track(ImageFixtures::png($this->dir))));
            self::fail('Expected an oversize rejection');
        } catch (HttpException $e) {
            self::assertSame(413, $e->getStatus());
            self::assertSame('payload_too_large', $e->getErrorCode());
        }
    }

    public function testUploadErrorsAre422(): void
    {
        try {
            (new ImageValidator(5_000_000))->validate(ImageFixtures::asUpload($this->track(ImageFixtures::png($this->dir)), 'x.png', UPLOAD_ERR_PARTIAL));
            self::fail('Expected a validation failure');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('images', $e->getFields());
        }
    }

    private function track(string $path): string
    {
        $this->created[] = $path;
        return $path;
    }
}
