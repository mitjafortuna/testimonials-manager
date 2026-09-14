<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Exception\NotFoundException;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Storage\ImageStorage;

/**
 * Streams uploaded images from outside the web root. The filename must match the UUID pattern
 * exactly — no path separators can ever reach the filesystem.
 */
final class MediaController
{
    public function __construct(private readonly ImageStorage $storage)
    {
    }

    public function show(Request $request): Response
    {
        $name = $request->attribute('filename');
        if (!ImageStorage::isSafeFilename($name) || !is_file($this->storage->path($name))) {
            throw new NotFoundException('Not found');
        }
        return Response::file($this->storage->path($name), ImageStorage::mimeFor($name))
            ->withHeader('Cache-Control', 'private, max-age=86400');
    }
}
