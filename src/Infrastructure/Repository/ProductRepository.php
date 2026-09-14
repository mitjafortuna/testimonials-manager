<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

class ProductRepository
{
    /** Whitelisted sort expressions, keyed by API sort name. */
    public const SORT_COLUMNS = [
        'sku' => 'p.parent_sku',
        'title' => 'p.title',
        'landings' => 'landing_count',
        'testimonials' => 'testimonial_count',
    ];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @param list<array{parent_sku:string,title:string,description:?string,image:?string}> $products */
    public function upsertMany(array $products): void
    {
        if ($products === []) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (parent_sku, title, description, image) VALUES (:sku, :title, :description, :image)
             ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), image = VALUES(image)',
        );
        foreach ($products as $p) {
            $stmt->execute(['sku' => $p['parent_sku'], 'title' => $p['title'], 'description' => $p['description'], 'image' => $p['image']]);
        }
    }

    /** @return array<string,int> */
    public function idsBySku(): array
    {
        $map = [];
        foreach ($this->pdo->query('SELECT id, parent_sku FROM products ORDER BY parent_sku') as $row) {
            $map[$row['parent_sku']] = (int) $row['id'];
        }
        return $map;
    }

    /**
     * @return list<array{id:int,parent_sku:string,title:string,image:?string,landing_count:int,testimonial_count:int}>
     */
    public function search(string $q, string $sortColumn, string $dir, int $limit, int $offset): array
    {
        if (!in_array($sortColumn, self::SORT_COLUMNS, true) || !in_array($dir, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Invalid sort');
        }
        $sql = 'SELECT p.id, p.parent_sku, p.title, p.image,
                  (SELECT COUNT(*) FROM landings l WHERE l.product_id = p.id AND l.removed_at IS NULL) AS landing_count,
                  (SELECT COUNT(*) FROM testimonials t JOIN landings l2 ON l2.id = t.landing_id
                     WHERE l2.product_id = p.id AND l2.removed_at IS NULL) AS testimonial_count
                FROM products p ' . $this->whereSearch($q) . "
                ORDER BY $sortColumn $dir, p.id ASC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        $this->bindSearch($stmt, $q);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'parent_sku' => (string) $row['parent_sku'],
                'title' => (string) $row['title'],
                'image' => $row['image'] === null ? null : (string) $row['image'],
                'landing_count' => (int) $row['landing_count'],
                'testimonial_count' => (int) $row['testimonial_count'],
            ];
        }
        return $rows;
    }

    public function countSearch(string $q): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products p ' . $this->whereSearch($q));
        $this->bindSearch($stmt, $q);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    private function whereSearch(string $q): string
    {
        return $q === '' ? '' : 'WHERE p.parent_sku LIKE :q1 OR p.title LIKE :q2 OR p.description LIKE :q3';
    }

    private function bindSearch(\PDOStatement $stmt, string $q): void
    {
        if ($q === '') {
            return;
        }
        // Escape LIKE metacharacters so user input is matched literally.
        $like = '%' . addcslashes($q, '%_\\') . '%';
        foreach ([':q1', ':q2', ':q3'] as $p) {
            $stmt->bindValue($p, $like);
        }
    }
}
