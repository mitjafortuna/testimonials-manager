<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\UploadedFiles;
use PHPUnit\Framework\TestCase;

final class UploadedFilesTest extends TestCase
{
    public function testFlattensArrayShape(): void
    {
        $files = ['images' => ['name' => ['a.jpg', 'b.png'], 'type' => ['x', 'y'], 'tmp_name' => ['/t/a', '/t/b'], 'error' => [0, 0], 'size' => [10, 20]]];
        $list = UploadedFiles::normalize($files, 'images');
        self::assertCount(2, $list);
        self::assertSame(['name' => 'b.png', 'type' => 'y', 'tmp_name' => '/t/b', 'error' => 0, 'size' => 20], $list[1]);
    }

    public function testSingleShapeAndMissingField(): void
    {
        $files = ['images' => ['name' => 'a.jpg', 'type' => 'x', 'tmp_name' => '/t/a', 'error' => 0, 'size' => 10]];
        self::assertCount(1, UploadedFiles::normalize($files, 'images'));
        self::assertSame([], UploadedFiles::normalize([], 'images'));
    }

    public function testSkipsNoFileEntries(): void
    {
        $files = ['images' => ['name' => [''], 'type' => [''], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_NO_FILE], 'size' => [0]]];
        self::assertSame([], UploadedFiles::normalize($files, 'images'));
    }
}
