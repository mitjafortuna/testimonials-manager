<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Auth\CurrentUser;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use App\Domain\Image\ImageValidator;
use App\Infrastructure\Repository\ChangeLogRepository;
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
        private readonly ChangeLogRepository $changeLog,
    ) {
    }

    /**
     * @param list<array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
     * @return list<ImgRow>
     */
    public function upload(int $testimonialId, array $files, ?string $crop = null, bool $convertWebp = false): array
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
            $names = $this->storage->store($c['tmp_path'], $c['ext'], $crop, $convertWebp);
            $size = (int) filesize($this->storage->path($names['filename']));
            $id = $this->images->insert($testimonialId, [
                'filename' => $names['filename'], 'thumb_filename' => $names['thumb_filename'], 'mime' => $names['mime'],
                'size_bytes' => $size, 'width' => $names['width'], 'height' => $names['height'],
            ], $this->user->id());
            $row = $this->images->find($id);
            if ($row !== null) {
                $stored[] = $row;
                $this->changeLog->record('testimonial', $testimonialId, 'image_added', ['filename' => $names['filename']], $this->user->id());
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
        $this->changeLog->record('testimonial', $row['testimonial_id'], 'image_removed', ['filename' => $row['filename']], $this->user->id());
        $this->images->delete($imageId);
        $this->storage->delete($row['filename'], $row['thumb_filename']);
    }

    /**
     * @param list<int> $ids
     * @return list<ImgRow>
     */
    public function reorder(int $testimonialId, array $ids): array
    {
        if ($this->testimonials->find($testimonialId) === null) {
            throw new NotFoundException("Testimonial $testimonialId not found");
        }
        $existing = $this->images->listByTestimonialIds([$testimonialId])[$testimonialId] ?? [];
        $existingIds = array_column($existing, 'id');
        $sortedExisting = $existingIds;
        sort($sortedExisting);
        $sortedGiven = $ids;
        sort($sortedGiven);
        if ($sortedExisting !== $sortedGiven) {
            throw new ValidationException(['ids' => 'Must list exactly the images belonging to this testimonial']);
        }
        $this->images->reorder($testimonialId, $ids);
        return $this->images->listByTestimonialIds([$testimonialId])[$testimonialId] ?? [];
    }
}
