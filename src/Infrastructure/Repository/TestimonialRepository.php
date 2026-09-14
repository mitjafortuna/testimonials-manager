<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

/**
 * @phpstan-type Row array{id:int,landing_id:int,author_name:string,text:string,rating:?int,gender:string,url:?string,is_active:bool,sort_order:int,created_at:string,updated_at:string,created_by:?int,updated_by:?int}
 */
final class TestimonialRepository
{
    private const EDITABLE = ['author_name', 'text', 'rating', 'gender', 'url', 'is_active', 'sort_order'];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return list<Row> */
    public function listByLanding(int $landingId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM testimonials WHERE landing_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$landingId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    /** @return Row|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM testimonials WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->cast($row);
    }

    public function countByLanding(int $landingId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM testimonials WHERE landing_id = ?');
        $stmt->execute([$landingId]);
        return (int) $stmt->fetchColumn();
    }

    public function nextSortOrder(int $landingId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order) + 1, 0) FROM testimonials WHERE landing_id = ?');
        $stmt->execute([$landingId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $fields  validator output (all keys) */
    public function insert(int $landingId, array $fields, ?int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO testimonials (landing_id, author_name, text, rating, gender, url, is_active, sort_order, created_by, updated_by)
             VALUES (:landing_id, :author_name, :text, :rating, :gender, :url, :is_active, :sort_order, :created_by, :updated_by)',
        );
        $stmt->execute([
            'landing_id' => $landingId,
            'author_name' => $fields['author_name'],
            'text' => $fields['text'],
            'rating' => $fields['rating'],
            'gender' => $fields['gender'],
            'url' => $fields['url'],
            'is_active' => (int) $fields['is_active'],
            'sort_order' => $fields['sort_order'] ?? $this->nextSortOrder($landingId),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $fields  subset of editable columns */
    public function update(int $id, array $fields, ?int $userId): void
    {
        if ($fields === []) {
            return;
        }
        $set = [];
        $params = ['id' => $id, 'updated_by' => $userId];
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::EDITABLE, true)) {
                throw new \InvalidArgumentException("Column '$column' is not editable");
            }
            $set[] = "`$column` = :$column";
            $params[$column] = $column === 'is_active' ? (int) $value : $value;
        }
        $set[] = 'updated_by = :updated_by';
        $this->pdo->prepare('UPDATE testimonials SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM testimonials WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    /** @param list<int> $ids  full ordered set of every testimonial id belonging to $landingId */
    public function reorder(int $landingId, array $ids): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE testimonials SET sort_order = ? WHERE id = ? AND landing_id = ?');
            foreach ($ids as $i => $id) {
                $stmt->execute([$i, $id, $landingId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param list<Row> $sourceRows */
    public function replaceOrAppend(int $targetLandingId, array $sourceRows, bool $replace, ?int $userId): void
    {
        $this->pdo->beginTransaction();
        try {
            if ($replace) {
                $this->pdo->prepare('DELETE FROM testimonials WHERE landing_id = ?')->execute([$targetLandingId]);
            }
            $next = $replace ? 0 : $this->nextSortOrder($targetLandingId);
            $stmt = $this->pdo->prepare(
                'INSERT INTO testimonials (landing_id, author_name, text, rating, gender, url, is_active, sort_order, created_by, updated_by)
                 VALUES (:landing_id, :author_name, :text, :rating, :gender, :url, :is_active, :sort_order, :created_by, :updated_by)',
            );
            foreach ($sourceRows as $i => $row) {
                $stmt->execute([
                    'landing_id' => $targetLandingId, 'author_name' => $row['author_name'], 'text' => $row['text'],
                    'rating' => $row['rating'], 'gender' => $row['gender'], 'url' => $row['url'],
                    'is_active' => (int) $row['is_active'], 'sort_order' => $next + $i,
                    'created_by' => $userId, 'updated_by' => $userId,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param list<int> $ids */
    public function bulkSetActive(array $ids, bool $active, ?int $userId): int
    {
        if ($ids === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("UPDATE testimonials SET is_active = ?, updated_by = ? WHERE id IN ($marks)");
        $stmt->execute([(int) $active, $userId, ...$ids]);
        return $stmt->rowCount();
    }

    /** @param list<int> $ids */
    public function bulkDelete(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM testimonials WHERE id IN ($marks)");
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    /**
     * @param array<string,mixed> $r
     * @return Row
     */
    private function cast(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'landing_id' => (int) $r['landing_id'],
            'author_name' => (string) $r['author_name'],
            'text' => (string) $r['text'],
            'rating' => $r['rating'] === null ? null : (int) $r['rating'],
            'gender' => (string) $r['gender'],
            'url' => $r['url'] === null ? null : (string) $r['url'],
            'is_active' => (bool) $r['is_active'],
            'sort_order' => (int) $r['sort_order'],
            'created_at' => (string) $r['created_at'],
            'updated_at' => (string) $r['updated_at'],
            'created_by' => $r['created_by'] === null ? null : (int) $r['created_by'],
            'updated_by' => $r['updated_by'] === null ? null : (int) $r['updated_by'],
        ];
    }
}
