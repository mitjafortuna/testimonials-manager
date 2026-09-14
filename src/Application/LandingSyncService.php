<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Exception\ConflictException;
use App\Domain\Exception\UpstreamException;
use App\Infrastructure\Repository\LandingRepository;
use App\Infrastructure\Repository\ProductRepository;
use App\Infrastructure\Repository\SyncRunRepository;
use App\Infrastructure\Upstream\LandingsApiClientInterface;
use App\Support\Clock;

/**
 * Synchronises the local landings/products tables with the upstream GET /landings feed.
 * Upsert by upstream id — never truncates — so testimonials keep their landing reference.
 */
final class LandingSyncService
{
    private const LOCK_NAME = 'testimonials_landing_sync';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly LandingsApiClientInterface $client,
        private readonly ProductRepository $products,
        private readonly LandingRepository $landings,
        private readonly SyncRunRepository $runs,
        private readonly Clock $clock,
    ) {
    }

    /** @return array{id:int,added:int,updated:int,removed:int,duration_ms:int} */
    public function run(): array
    {
        $lockStmt = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lockStmt->execute([self::LOCK_NAME]);
        $lock = $lockStmt->fetchColumn();
        if ((int) $lock !== 1) {
            throw new ConflictException('A sync is already running');
        }
        $startedAt = $this->clock->now();
        $t0 = hrtime(true);
        $runId = null;
        $committed = false;
        try {
            $runId = $this->runs->start($startedAt->format('Y-m-d H:i:s'));
            $rows = $this->client->fetchAll();
            if ($rows === []) {
                throw new UpstreamException('Upstream returned no landings; refusing to sync');
            }
            $this->pdo->beginTransaction();
            try {
                $stats = $this->apply($rows, $startedAt->format('Y-m-d H:i:s'));
                $this->pdo->commit();
                $committed = true;
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
            $this->runs->finish($runId, 'ok', $this->clock->now()->format('Y-m-d H:i:s'), $stats['added'], $stats['updated'], $stats['removed'], null);
            return ['id' => $runId, 'duration_ms' => (int) ((hrtime(true) - $t0) / 1_000_000)] + $stats;
        } catch (\Throwable $e) {
            // The transaction already committed; the data landed, so don't overwrite the run as failed — just rethrow.
            if ($committed) {
                throw $e;
            }
            if ($runId !== null) {
                try {
                    $this->runs->finish($runId, 'failed', $this->clock->now()->format('Y-m-d H:i:s'), 0, 0, 0, $e->getMessage());
                } catch (\Throwable $finishError) {
                    // Recording the failure must never hide the original failure.
                    error_log(sprintf('[sync] failed to record failed run #%d: %s', $runId, $finishError->getMessage()));
                }
            }
            throw $e;
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::LOCK_NAME]);
        }
    }

    /**
     * @param list<array{id:int,parent_sku:string,country:string,is_master:bool,url:string,title:string,description:?string,image:?string,status:?string}> $rows
     * @return array{added:int,updated:int,removed:int}
     */
    private function apply(array $rows, string $now): array
    {
        // 1. products: one per parent_sku, described by its master landing (fallback: first landing seen)
        $products = [];
        foreach ($rows as $r) {
            $sku = $r['parent_sku'];
            if (!isset($products[$sku]) || $r['is_master']) {
                $products[$sku] = ['parent_sku' => $sku, 'title' => $r['title'], 'description' => $r['description'], 'image' => $r['image']];
            }
        }
        $this->products->upsertMany(array_values($products));
        $ids = $this->products->idsBySku();

        // 2. landings: upsert by upstream id
        $landingRows = [];
        foreach ($rows as $r) {
            $landingRows[] = $r + ['product_id' => $ids[$r['parent_sku']]];
        }
        $result = $this->landings->upsertMany($landingRows, $now);

        // 3. anything not in the feed any more is soft-deleted; its testimonials stay
        $removed = $this->landings->markRemovedExcept(array_column($rows, 'id'), $now);

        return ['added' => $result['added'], 'updated' => $result['updated'], 'removed' => $removed];
    }
}
