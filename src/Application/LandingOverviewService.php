<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Exception\NotFoundException;
use App\Infrastructure\Repository\LandingRepository;

final class LandingOverviewService
{
    public function __construct(private readonly LandingRepository $landings)
    {
    }

    /** @return array{data: list<array<string,mixed>>} */
    public function forProduct(string $sku): array
    {
        $rows = $this->landings->listByProductSku($sku);
        if ($rows === []) {
            throw new NotFoundException("Product '$sku' not found");
        }
        $masterCount = 0;
        foreach ($rows as $row) {
            if ($row['is_master']) {
                $masterCount = $row['testimonial_count'];
                break;
            }
        }
        $data = [];
        foreach ($rows as $row) {
            $inherits = !$row['is_master'] && $row['testimonial_count'] === 0;
            $data[] = $row + ['inherits_from_master' => $inherits, 'inherited_count' => $inherits ? $masterCount : 0];
        }
        return ['data' => $data];
    }
}
