<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Auth\CurrentUser;
use App\Domain\Exception\NotFoundException;
use App\Domain\Testimonial\RatingResolver;
use App\Domain\Testimonial\TestimonialValidator;
use App\Infrastructure\Repository\LandingRepository;
use App\Infrastructure\Repository\TestimonialRepository;

/**
 * @phpstan-import-type Row from TestimonialRepository
 */
final class TestimonialService
{
    public function __construct(
        private readonly TestimonialRepository $testimonials,
        private readonly LandingRepository $landings,
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
        $data = array_map([$this, 'present'], $this->testimonials->listByLanding($sourceId));
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
        return $this->present($this->existing($id));
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
        $this->testimonials->delete($id);
    }

    /**
     * @param Row $row
     * @return array<string,mixed>
     */
    public function present(array $row): array
    {
        return $row + ['rating_display' => $this->ratings->display($row['rating']), 'images' => []];
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
