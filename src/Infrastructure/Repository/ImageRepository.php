<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

/**
 * @phpstan-type ImgRow array{id:int,testimonial_id:int,filename:string,thumb_filename:string,mime:string,size_bytes:int,width:int,height:int,sort_order:int,created_at:string,created_by:?int}
 */
final class ImageRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @param list<int> $testimonialIds
     * @return array<int, list<ImgRow>>
     */
    public function listByTestimonialIds(array $testimonialIds): array
    {
        if ($testimonialIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($testimonialIds), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM testimonial_images WHERE testimonial_id IN ($marks) ORDER BY testimonial_id, sort_order ASC, id ASC");
        $stmt->execute(array_values($testimonialIds));
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $row = $this->cast($r);
            $map[$row['testimonial_id']][] = $row;
        }
        return $map;
    }

    /** @return ImgRow|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM testimonial_images WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->cast($row);
    }

    /** @param array{filename:string,thumb_filename:string,mime:string,size_bytes:int,width:int,height:int} $data */
    public function insert(int $testimonialId, array $data, ?int $userId): int
    {
        $next = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order) + 1, 0) FROM testimonial_images WHERE testimonial_id = ?');
        $next->execute([$testimonialId]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO testimonial_images (testimonial_id, filename, thumb_filename, mime, size_bytes, width, height, sort_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([$testimonialId, $data['filename'], $data['thumb_filename'], $data['mime'], $data['size_bytes'], $data['width'], $data['height'], (int) $next->fetchColumn(), $userId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM testimonial_images WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string,mixed> $r
     * @return ImgRow
     */
    private function cast(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'testimonial_id' => (int) $r['testimonial_id'],
            'filename' => (string) $r['filename'], 'thumb_filename' => (string) $r['thumb_filename'], 'mime' => (string) $r['mime'],
            'size_bytes' => (int) $r['size_bytes'], 'width' => (int) $r['width'], 'height' => (int) $r['height'],
            'sort_order' => (int) $r['sort_order'], 'created_at' => (string) $r['created_at'],
            'created_by' => $r['created_by'] === null ? null : (int) $r['created_by'],
        ];
    }
}
