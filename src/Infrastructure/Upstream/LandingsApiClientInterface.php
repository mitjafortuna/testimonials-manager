<?php

declare(strict_types=1);

namespace App\Infrastructure\Upstream;

/**
 * @phpstan-type LandingRow array{id:int, parent_sku:string, country:string, is_master:bool, url:string, title:string, description:?string, image:?string, status:?string}
 */
interface LandingsApiClientInterface
{
    /** @return list<LandingRow> */
    public function fetchAll(): array;
}
