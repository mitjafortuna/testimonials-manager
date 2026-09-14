<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

final class LandingRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @param list<array{id:int,product_id:int,country:string,is_master:bool,url:string,title:string,description:?string,image:?string,status:?string}> $rows
     * @return array{added:int,updated:int}
     */
    public function upsertMany(array $rows, string $syncedAt): array
    {
        $before = $this->snapshot(array_column($rows, 'id'));
        $stmt = $this->pdo->prepare(
            'INSERT INTO landings (id, product_id, country, is_master, url, title, description, image, status, last_synced_at)
             VALUES (:id, :product_id, :country, :is_master, :url, :title, :description, :image, :status, :synced_at)
             ON DUPLICATE KEY UPDATE
               product_id = VALUES(product_id), country = VALUES(country), is_master = VALUES(is_master),
               url = VALUES(url), title = VALUES(title), description = VALUES(description), image = VALUES(image),
               status = VALUES(status), removed_at = NULL, last_synced_at = VALUES(last_synced_at)',
        );
        $added = 0;
        $updated = 0;
        foreach ($rows as $r) {
            $stmt->execute([
                'id' => $r['id'], 'product_id' => $r['product_id'], 'country' => $r['country'], 'is_master' => (int) $r['is_master'],
                'url' => $r['url'], 'title' => $r['title'], 'description' => $r['description'], 'image' => $r['image'],
                'status' => $r['status'], 'synced_at' => $syncedAt,
            ]);
            if (!isset($before[$r['id']])) {
                $added++;
            } elseif (array_diff_key($before[$r['id']], ['removed' => 1]) !== $this->comparable($r) || $before[$r['id']]['removed'] === true) {
                $updated++;
            }
        }
        return ['added' => $added, 'updated' => $updated];
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string,mixed>>
     */
    private function snapshot(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, product_id, country, is_master, url, title, description, image, status, removed_at FROM landings WHERE id IN ($marks)");
        $stmt->execute($ids);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['id']] = $this->comparable($row) + ['removed' => $row['removed_at'] !== null];
        }
        return $map;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function comparable(array $r): array
    {
        return [
            'product_id' => (int) $r['product_id'], 'country' => (string) $r['country'], 'is_master' => (bool) $r['is_master'],
            'url' => (string) $r['url'], 'title' => (string) $r['title'], 'description' => $r['description'] === null ? null : (string) $r['description'],
            'image' => $r['image'] === null ? null : (string) $r['image'], 'status' => $r['status'] === null ? null : (string) $r['status'],
        ];
    }

    /** @param list<int> $keepIds */
    public function markRemovedExcept(array $keepIds, string $now): int
    {
        if ($keepIds === []) {
            $stmt = $this->pdo->prepare('UPDATE landings SET removed_at = ? WHERE removed_at IS NULL');
            $stmt->execute([$now]);
            return $stmt->rowCount();
        }
        $marks = implode(',', array_fill(0, count($keepIds), '?'));
        $stmt = $this->pdo->prepare("UPDATE landings SET removed_at = ? WHERE removed_at IS NULL AND id NOT IN ($marks)");
        $stmt->execute([$now, ...$keepIds]);
        return $stmt->rowCount();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM landings WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
