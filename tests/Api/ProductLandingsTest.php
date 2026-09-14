<?php

declare(strict_types=1);

namespace Tests\Api;

final class ProductLandingsTest extends ApiTestCase
{
    public function testLandingsOfSeededProduct(): void
    {
        $r = $this->request('GET', '/api/products/abforge/landings');
        self::assertSame(200, $r['status']);
        $data = $r['json']['data'];
        self::assertGreaterThan(10, count($data));
        self::assertTrue($data[0]['is_master']);
        self::assertSame('EN', $data[0]['country']);
        self::assertSame(61763, $data[0]['id']);
        self::assertFalse($data[0]['inherits_from_master']);
        $inheriting = array_values(array_filter($data, fn ($l) => $l['inherits_from_master']));
        self::assertNotEmpty($inheriting, 'some localised landings inherit from EN');
        self::assertSame($data[0]['testimonial_count'], $inheriting[0]['inherited_count']);
        self::assertSame(0, $inheriting[0]['testimonial_count']);
    }

    public function testUnknownSkuIs404(): void
    {
        $r = $this->request('GET', '/api/products/does-not-exist/landings');
        self::assertSame(404, $r['status']);
        self::assertSame('not_found', $r['json']['error']['code']);
    }
}
