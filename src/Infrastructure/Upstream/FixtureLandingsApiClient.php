<?php

declare(strict_types=1);

namespace App\Infrastructure\Upstream;

/**
 * Replays a captured upstream response. Used by tests and by `APP_ENV=test` deployments without upstream access.
 * @phpstan-import-type LandingRow from LandingsApiClientInterface
 */
final class FixtureLandingsApiClient implements LandingsApiClientInterface
{
    /** @param list<LandingRow> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public static function fromFile(string $path): self
    {
        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return new self(array_map([CurlLandingsApiClient::class, 'normalise'], $json['data']));
    }

    public function fetchAll(): array
    {
        return $this->rows;
    }
}
