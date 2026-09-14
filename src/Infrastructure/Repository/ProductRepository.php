<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

final class ProductRepository
{
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
}
