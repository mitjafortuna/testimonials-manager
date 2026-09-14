<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Storage;

use App\Infrastructure\Storage\ImageStorage;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImageFixtures;

final class ImageStorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tm-storage-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testStoresWithUuidNameAndThumbnail(): void
    {
        $storage = new ImageStorage($this->dir, 300);
        $r = $storage->store(ImageFixtures::png(sys_get_temp_dir(), 1200, 600), 'png');
        self::assertTrue(ImageStorage::isSafeFilename($r['filename']));
        self::assertTrue(ImageStorage::isSafeFilename($r['thumb_filename']));
        self::assertSame(str_replace('.png', '_thumb.png', $r['filename']), $r['thumb_filename']);
        self::assertFileExists($storage->path($r['filename']));
        self::assertFileExists($storage->path($r['thumb_filename']));
        self::assertSame([300, 150], $this->dimensions($storage->path($r['thumb_filename'])));
        self::assertSame('image/png', ImageStorage::mimeFor($r['filename']));
    }

    public function testThumbnailNeverUpscalesAndKeepsFormat(): void
    {
        $storage = new ImageStorage($this->dir, 300);
        $r = $storage->store(ImageFixtures::jpeg(sys_get_temp_dir(), 100, 80), 'jpg');
        self::assertSame([100, 80], $this->dimensions($storage->path($r['thumb_filename'])));
        self::assertSame('image/jpeg', mime_content_type($storage->path($r['thumb_filename'])));
        $r = $storage->store(ImageFixtures::webp(sys_get_temp_dir(), 900, 900), 'webp');
        self::assertSame('image/webp', mime_content_type($storage->path($r['thumb_filename'])));
    }

    public function testMovesTheSourceFileOutOfTheWay(): void
    {
        $storage = new ImageStorage($this->dir, 300);
        $source = ImageFixtures::png(sys_get_temp_dir());
        $storage->store($source, 'png');
        self::assertFileDoesNotExist($source);
    }

    public function testDeleteIsIdempotent(): void
    {
        $storage = new ImageStorage($this->dir, 300);
        $r = $storage->store(ImageFixtures::png(sys_get_temp_dir()), 'png');
        $storage->delete($r['filename'], $r['thumb_filename']);
        self::assertFileDoesNotExist($storage->path($r['filename']));
        self::assertFileDoesNotExist($storage->path($r['thumb_filename']));
        $storage->delete($r['filename'], $r['thumb_filename']);   // no exception
        self::assertTrue(true);
    }

    public function testIsSafeFilenameRejectsTraversalAndForeignNames(): void
    {
        foreach (['../x.jpg', 'a.jpg', '0123456789abcdef.jpg', 'ffffffff-ffff-4fff-8fff-ffffffffffff.gif', 'ffffffff-ffff-4fff-8fff-ffffffffffff.jpg/../../.env'] as $bad) {
            self::assertFalse(ImageStorage::isSafeFilename($bad), $bad);
        }
        self::assertTrue(ImageStorage::isSafeFilename('ffffffff-ffff-4fff-8fff-ffffffffffff_thumb.webp'));
    }

    public function testDownscalesLargeMainImageButNeverUpscales(): void
    {
        $storage = new ImageStorage($this->dir, 300);
        $big = $storage->store(ImageFixtures::jpeg(sys_get_temp_dir(), 2000, 1000), 'jpg');
        self::assertSame([1600, 800], $this->dimensions($storage->path($big['filename'])));
        self::assertSame(1600, $big['width']);
        self::assertSame(800, $big['height']);

        $small = $storage->store(ImageFixtures::jpeg(sys_get_temp_dir(), 640, 480), 'jpg');
        self::assertSame([640, 480], $this->dimensions($storage->path($small['filename'])));
    }

    public function testConvertsToWebpWhenRequested(): void
    {
        $storage = new ImageStorage($this->dir, 300);
        $r = $storage->store(ImageFixtures::png(sys_get_temp_dir(), 640, 480), 'png', null, true);
        self::assertStringEndsWith('.webp', $r['filename']);
        self::assertStringEndsWith('.webp', $r['thumb_filename']);
        self::assertSame('image/webp', $r['mime']);
        self::assertSame('image/webp', mime_content_type($storage->path($r['filename'])));
        self::assertSame('image/webp', mime_content_type($storage->path($r['thumb_filename'])));
    }

    public function testCropsToSquareWhenRequested(): void
    {
        $storage = new ImageStorage($this->dir, 300);
        $r = $storage->store(ImageFixtures::jpeg(sys_get_temp_dir(), 800, 400), 'jpg', 'square');
        self::assertSame(400, $r['width']);
        self::assertSame(400, $r['height']);
        self::assertSame([400, 400], $this->dimensions($storage->path($r['filename'])));
    }

    public function testDefaultCallSignatureIsUnchanged(): void
    {
        // The exact call every existing production and test call site makes — must keep working.
        $storage = new ImageStorage($this->dir, 300);
        $r = $storage->store(ImageFixtures::png(sys_get_temp_dir()), 'png');
        self::assertStringEndsWith('.png', $r['filename']);
        self::assertSame('image/png', $r['mime']);
    }

    /** @return array{0:int,1:int} */
    private function dimensions(string $path): array
    {
        $info = getimagesize($path);
        self::assertIsArray($info, "Not an image: $path");
        return [(int) $info[0], (int) $info[1]];
    }
}
