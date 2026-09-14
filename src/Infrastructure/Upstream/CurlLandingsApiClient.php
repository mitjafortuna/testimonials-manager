<?php

declare(strict_types=1);

namespace App\Infrastructure\Upstream;

use App\Domain\Exception\UpstreamException;

/**
 * @phpstan-import-type LandingRow from LandingsApiClientInterface
 */
final class CurlLandingsApiClient implements LandingsApiClientInterface
{
    public function __construct(
        private readonly HttpTransportInterface $transport,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $pageSize = 1000,
    ) {
        if ($this->pageSize <= 0) {
            throw new \InvalidArgumentException('pageSize must be a positive integer');
        }
    }

    public function fetchAll(): array
    {
        $all = [];
        $offset = 0;
        do {
            $sep = str_contains($this->baseUrl, '?') ? '&' : '?';
            $url = $this->baseUrl . $sep . http_build_query(['limit' => $this->pageSize, 'offset' => $offset]);
            $res = $this->transport->get($url, ['X-Api-Key: ' . $this->apiKey, 'Accept: application/json']);
            if ($res['status'] !== 200) {
                throw new UpstreamException("Upstream returned HTTP {$res['status']}");
            }
            $json = json_decode($res['body'], true);
            if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
                throw new UpstreamException('Upstream returned invalid JSON');
            }
            foreach ($json['data'] as $raw) {
                $all[] = self::normalise($raw);
            }
            $count = count($json['data']);
            $total = is_array($json['meta'] ?? null) ? $json['meta']['total'] ?? null : null;
            $offset += $count;
            // Upstream may cap `limit` below what we asked for; when it reports meta.total, page
            // by that instead of assuming a short page means "done" (a page could legitimately be
            // shorter than our requested pageSize while more rows remain).
            $more = $total === null ? $count === $this->pageSize : $offset < (int) $total;
        } while ($count > 0 && $more);
        return $all;
    }

    /**
     * @param array<string,mixed> $raw
     * @return LandingRow
     */
    public static function normalise(array $raw): array
    {
        foreach (['id', 'parent_sku', 'country', 'url'] as $required) {
            if (!isset($raw[$required])) {
                throw new UpstreamException("Upstream landing is missing '$required'");
            }
        }
        return [
            'id' => (int) $raw['id'],
            'parent_sku' => (string) $raw['parent_sku'],
            'country' => strtoupper((string) $raw['country']),
            'is_master' => filter_var($raw['is_master'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'url' => (string) $raw['url'],
            'title' => (string) ($raw['title'] ?? ''),
            'description' => isset($raw['description']) ? (string) $raw['description'] : null,
            'image' => isset($raw['image']) ? (string) $raw['image'] : null,
            'status' => isset($raw['status']) ? (string) $raw['status'] : null,
        ];
    }
}
