<?php

declare(strict_types=1);

namespace Tests\Api;

final class ProductsTest extends ApiTestCase
{
    public function testListsSeededProductsWithCounts(): void
    {
        $r = $this->request('GET', '/api/products?per_page=5&sort=sku');
        self::assertSame(200, $r['status']);
        self::assertCount(5, $r['json']['data']);
        self::assertSame(10, $r['json']['meta']['total']);
        $first = $r['json']['data'][0];
        self::assertSame('abforge', $first['sku']);
        self::assertGreaterThan(10, $first['landing_count']);
        self::assertGreaterThan(0, $first['testimonial_count']);
        self::assertArrayHasKey('title', $first);
        self::assertArrayHasKey('image', $first);
    }

    public function testSearchAndValidation(): void
    {
        $r = $this->request('GET', '/api/products?search=abforge');
        self::assertSame(1, $r['json']['meta']['total']);
        $r = $this->request('GET', '/api/products?sort=nope');
        self::assertSame(422, $r['status']);
        self::assertSame('validation_failed', $r['json']['error']['code']);
        self::assertArrayHasKey('sort', $r['json']['error']['fields']);
    }
}
