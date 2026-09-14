<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\ProductSearchService;
use App\Domain\Exception\ValidationException;
use App\Infrastructure\Repository\ProductRepository;
use PHPUnit\Framework\TestCase;

final class ProductSearchServiceTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $captured = [];

    private function service(): ProductSearchService
    {
        $repo = $this->createMock(ProductRepository::class);
        $repo->method('search')->willReturnCallback(function (string $q, string $col, string $dir, int $limit, int $offset) {
            $this->captured = compact('q', 'col', 'dir', 'limit', 'offset');
            return [];
        });
        $repo->method('countSearch')->willReturn(42);
        return new ProductSearchService($repo);
    }

    public function testDefaults(): void
    {
        $result = $this->service()->search([]);
        self::assertSame(['q' => '', 'col' => 'p.parent_sku', 'dir' => 'ASC', 'limit' => 20, 'offset' => 0], $this->captured);
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 42, 'sort' => 'sku', 'dir' => 'asc', 'search' => ''], $result['meta']);
    }

    public function testMapsSortAndPaging(): void
    {
        $this->service()->search(['search' => ' abs ', 'page' => '3', 'per_page' => '10', 'sort' => 'testimonials', 'dir' => 'desc']);
        self::assertSame(['q' => 'abs', 'col' => 'testimonial_count', 'dir' => 'DESC', 'limit' => 10, 'offset' => 20], $this->captured);
    }

    public function testRejectsBadInput(): void
    {
        foreach ([['page' => '0'], ['per_page' => '101'], ['sort' => 'id'], ['dir' => 'sideways'], ['page' => 'x']] as $bad) {
            try {
                $this->service()->search($bad);
                self::fail('expected ValidationException for ' . json_encode($bad));
            } catch (ValidationException $e) {
                self::assertSame(array_keys($bad), array_keys($e->getFields()));
            }
        }
    }
}
