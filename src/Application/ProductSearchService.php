<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Exception\ValidationException;
use App\Infrastructure\Repository\ProductRepository;

final class ProductSearchService
{
    private const MAX_PER_PAGE = 100;
    private const DEFAULT_PER_PAGE = 20;

    public function __construct(private readonly ProductRepository $products)
    {
    }

    /**
     * @param array<string,mixed> $query  raw query-string values
     * @return array{data: list<array{sku:string,title:string,image:?string,landing_count:int,testimonial_count:int}>, meta: array{page:int,per_page:int,total:int,sort:string,dir:string,search:string}}
     */
    public function search(array $query): array
    {
        $errors = [];
        $search = trim((string) ($query['search'] ?? ''));
        $page = $this->int($query, 'page', 1, 1, PHP_INT_MAX, $errors);
        $perPage = $this->int($query, 'per_page', self::DEFAULT_PER_PAGE, 1, self::MAX_PER_PAGE, $errors);
        $sort = (string) ($query['sort'] ?? 'sku');
        if (!isset(ProductRepository::SORT_COLUMNS[$sort])) {
            $errors['sort'] = 'Must be one of: ' . implode(', ', array_keys(ProductRepository::SORT_COLUMNS));
        }
        $dir = strtolower((string) ($query['dir'] ?? 'asc'));
        if (!in_array($dir, ['asc', 'desc'], true)) {
            $errors['dir'] = 'Must be asc or desc';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $rows = $this->products->search($search, ProductRepository::SORT_COLUMNS[$sort], strtoupper($dir), $perPage, ($page - 1) * $perPage);
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'sku' => $row['parent_sku'],
                'title' => $row['title'],
                'image' => $row['image'],
                'landing_count' => $row['landing_count'],
                'testimonial_count' => $row['testimonial_count'],
            ];
        }
        return [
            'data' => $data,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $this->products->countSearch($search), 'sort' => $sort, 'dir' => $dir, 'search' => $search],
        ];
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,string> $errors
     */
    private function int(array $query, string $key, int $default, int $min, int $max, array &$errors): int
    {
        if (!isset($query[$key]) || $query[$key] === '') {
            return $default;
        }
        $raw = $query[$key];
        if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw || (int) $raw < $min || (int) $raw > $max) {
            $errors[$key] = "Must be an integer between $min and $max";
            return $default;
        }
        return (int) $raw;
    }
}
