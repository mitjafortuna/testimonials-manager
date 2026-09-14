<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Auth\CurrentUser;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use App\Domain\Image\ImageValidator;
use App\Infrastructure\Repository\ImageRepository;
use App\Infrastructure\Repository\TestimonialRepository;
use App\Infrastructure\Storage\ImageStorage;

/**
 * @phpstan-import-type ImgRow from ImageRepository
 */
final class ImageService
{
    public function __construct(
        private readonly ImageRepository $images,
        private readonly TestimonialRepository $testimonials,
        private readonly ImageValidator $validator,
        private readonly ImageStorage $storage,
        private readonly CurrentUser $user,
    ) {
    }

    /**
     * @param list<array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
     * @return list<ImgRow>
     */
    public function upload(int $testimonialId, array $files): array
    {
        if ($this->testimonials->find($testimonialId) === null) {
            throw new NotFoundException("Testimonial $testimonialId not found");
        }
        if ($files === []) {
            throw new ValidationException(['images' => 'Choose at least one image']);
        }
        // Validate everything first so a bad file rejects the whole batch before anything is written.
        $checked = array_map(fn (array $f) => $this->validator->validate($f), $files);
        $stored = [];
        foreach ($checked as $c) {
            $names = $this->storage->store($c['tmp_path'], $c['ext']);
            $id = $this->images->insert($testimonialId, [
                'filename' => $names['filename'], 'thumb_filename' => $names['thumb_filename'], 'mime' => $c['mime'],
                'size_bytes' => $c['size'], 'width' => $c['width'], 'height' => $c['height'],
            ], $this->user->id());
            $row = $this->images->find($id);
            if ($row !== null) {
                $stored[] = $row;
            }
        }
        return $stored;
    }

    public function delete(int $imageId): void
    {
        $row = $this->images->find($imageId);
        if ($row === null) {
            throw new NotFoundException("Image $imageId not found");
        }
        $this->images->delete($imageId);
        $this->storage->delete($row['filename'], $row['thumb_filename']);
    }
}
