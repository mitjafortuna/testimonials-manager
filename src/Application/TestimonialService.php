<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Auth\CurrentUser;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use App\Domain\Testimonial\RatingResolver;
use App\Domain\Testimonial\TestimonialValidator;
use App\Infrastructure\Repository\ImageRepository;
use App\Infrastructure\Repository\LandingRepository;
use App\Infrastructure\Repository\TestimonialRepository;
use App\Infrastructure\Storage\ImageStorage;

/**
 * @phpstan-import-type Row from TestimonialRepository
 * @phpstan-import-type ImgRow from ImageRepository
 */
final class TestimonialService
{
    public const BULK_ACTIONS = ['activate', 'deactivate', 'delete'];

    public function __construct(
        private readonly TestimonialRepository $testimonials,
        private readonly LandingRepository $landings,
        private readonly ImageRepository $images,
        private readonly ImageStorage $imageStorage,
        private readonly TestimonialValidator $validator,
        private readonly RatingResolver $ratings,
        private readonly CurrentUser $user,
    ) {
    }

    /** @return array{data: list<array<string,mixed>>, meta: array{inherited: bool, source_landing_id: int, landing: array{id:int,country:string,is_master:bool,title:string,url:string}}} */
    public function listForLanding(int $landingId): array
    {
        $landing = $this->activeLanding($landingId);
        $sourceId = $landingId;
        $inherited = false;
        if (!(bool) $landing['is_master'] && $this->testimonials->countByLanding($landingId) === 0) {
            $master = $this->landings->findMasterFor($landingId);
            if ($master !== null) {
                $sourceId = (int) $master['id'];
                $inherited = true;
            }
        }
        $rows = $this->testimonials->listByLanding($sourceId);
        $images = $this->images->listByTestimonialIds(array_column($rows, 'id'));
        $data = array_map(fn (array $row) => $this->present($row, $images[$row['id']] ?? []), $rows);
        return [
            'data' => $data,
            'meta' => [
                'inherited' => $inherited,
                'source_landing_id' => $sourceId,
                'landing' => [
                    'id' => (int) $landing['id'], 'country' => (string) $landing['country'], 'is_master' => (bool) $landing['is_master'],
                    'title' => (string) $landing['title'], 'url' => (string) $landing['url'],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $row = $this->existing($id);
        return $this->present($row, $this->images->listByTestimonialIds([$id])[$id] ?? []);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(int $landingId, array $input): array
    {
        $this->activeLanding($landingId);
        $fields = $this->validator->validate($input);
        $id = $this->testimonials->insert($landingId, $fields, $this->user->id());
        return $this->get($id);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(int $id, array $input): array
    {
        $this->existing($id);
        $fields = $this->validator->validate($input, true);
        $this->testimonials->update($id, $fields, $this->user->id());
        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $this->existing($id);
        $images = $this->images->listByTestimonialIds([$id])[$id] ?? [];
        foreach ($images as $image) {
            $this->imageStorage->delete($image['filename'], $image['thumb_filename']);
        }
        $this->testimonials->delete($id);
    }

    /**
     * @param list<int> $ids
     * @return array{data: list<array<string,mixed>>, meta: array{inherited: bool, source_landing_id: int, landing: array{id:int,country:string,is_master:bool,title:string,url:string}}}
     */
    public function reorder(int $landingId, array $ids): array
    {
        $this->activeLanding($landingId);
        $existingIds = array_column($this->testimonials->listByLanding($landingId), 'id');
        $sortedExisting = $existingIds;
        sort($sortedExisting);
        $sortedGiven = $ids;
        sort($sortedGiven);
        if ($sortedExisting !== $sortedGiven) {
            throw new ValidationException(['ids' => 'Must list exactly the testimonials belonging to this landing']);
        }
        $this->testimonials->reorder($landingId, $ids);
        return $this->listForLanding($landingId);
    }

    /**
     * @param Row          $row
     * @param list<ImgRow> $images
     * @return array<string,mixed>
     */
    public function present(array $row, array $images = []): array
    {
        return $row + ['rating_display' => $this->ratings->display($row['rating']), 'images' => $images];
    }

    /** @return array{source: array{id:int,country:string,title:string}, mode:string, will_add:int, will_remove:int, items: list<array{author_name:string,text:string}>} */
    public function copyPreview(int $targetLandingId, int $sourceLandingId, string $mode): array
    {
        [, $source] = $this->copySources($targetLandingId, $sourceLandingId, $mode);
        $sourceRows = $this->testimonials->listByLanding($sourceLandingId);
        return [
            'source' => ['id' => (int) $source['id'], 'country' => (string) $source['country'], 'title' => (string) $source['title']],
            'mode' => $mode,
            'will_add' => count($sourceRows),
            'will_remove' => $mode === 'replace' ? $this->testimonials->countByLanding($targetLandingId) : 0,
            'items' => array_map(fn (array $r) => ['author_name' => $r['author_name'], 'text' => $r['text']], $sourceRows),
        ];
    }

    /** @return array<string,mixed> */
    public function copy(int $targetLandingId, int $sourceLandingId, string $mode): array
    {
        $this->copySources($targetLandingId, $sourceLandingId, $mode);
        if ($mode === 'replace') {
            $existing = $this->testimonials->listByLanding($targetLandingId);
            $images = $this->images->listByTestimonialIds(array_column($existing, 'id'));
            foreach ($existing as $row) {
                foreach ($images[$row['id']] ?? [] as $image) {
                    $this->imageStorage->delete($image['filename'], $image['thumb_filename']);
                }
            }
        }
        $sourceRows = $this->testimonials->listByLanding($sourceLandingId);
        $this->testimonials->replaceOrAppend($targetLandingId, $sourceRows, $mode === 'replace', $this->user->id());
        return $this->listForLanding($targetLandingId);
    }

    /** @return array{0: array<string,mixed>, 1: array<string,mixed>} [$target, $source] */
    private function copySources(int $targetLandingId, int $sourceLandingId, string $mode): array
    {
        if (!in_array($mode, ['replace', 'append'], true)) {
            throw new ValidationException(['mode' => 'Must be "replace" or "append"']);
        }
        if ($sourceLandingId === $targetLandingId) {
            throw new ValidationException(['source_landing_id' => 'Source and target must be different landings']);
        }
        $target = $this->activeLanding($targetLandingId);
        $source = $this->landings->find($sourceLandingId);
        if ($source === null || $source['removed_at'] !== null) {
            throw new NotFoundException("Landing $sourceLandingId not found");
        }
        if ((int) $source['product_id'] !== (int) $target['product_id']) {
            throw new ValidationException(['source_landing_id' => 'Source must belong to the same product']);
        }
        return [$target, $source];
    }

    /**
     * @param  list<int> $ids
     * @return array{data: list<array<string,mixed>>, meta: array{inherited: bool, source_landing_id: int, landing: array{id:int,country:string,is_master:bool,title:string,url:string}}}
     */
    public function bulkUpdate(int $landingId, array $ids, string $action): array
    {
        $this->activeLanding($landingId);
        if (!in_array($action, self::BULK_ACTIONS, true)) {
            throw new ValidationException(['action' => 'Must be one of: ' . implode(', ', self::BULK_ACTIONS)]);
        }
        $owned = array_column($this->testimonials->listByLanding($landingId), 'id');
        $ids = array_values(array_intersect($ids, $owned));
        if ($ids === []) {
            throw new ValidationException(['ids' => 'No matching testimonials for this landing']);
        }
        if ($action === 'delete') {
            $images = $this->images->listByTestimonialIds($ids);
            foreach ($ids as $id) {
                foreach ($images[$id] ?? [] as $image) {
                    $this->imageStorage->delete($image['filename'], $image['thumb_filename']);
                }
            }
            $this->testimonials->bulkDelete($ids);
        } else {
            $this->testimonials->bulkSetActive($ids, $action === 'activate', $this->user->id());
        }
        return $this->listForLanding($landingId);
    }

    /** @return Row */
    private function existing(int $id): array
    {
        $row = $this->testimonials->find($id);
        if ($row === null) {
            throw new NotFoundException("Testimonial $id not found");
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function activeLanding(int $landingId): array
    {
        $landing = $this->landings->find($landingId);
        if ($landing === null || $landing['removed_at'] !== null) {
            throw new NotFoundException("Landing $landingId not found");
        }
        return $landing;
    }
}
