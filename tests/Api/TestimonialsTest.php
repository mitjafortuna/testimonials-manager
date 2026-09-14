<?php

declare(strict_types=1);

namespace Tests\Api;

final class TestimonialsTest extends ApiTestCase
{
    private const EN = 61763;   // abforge master (seed)
    private const CZ = 63133;   // abforge CZ (seed) @phpstan-ignore classConstant.unused

    public function testCrudLifecycle(): void
    {
        $created = $this->request('POST', '/api/landings/' . self::EN . '/testimonials', ['author_name' => 'Api Tester', 'text' => 'Created via API', 'rating' => 'random', 'gender' => 'female']);
        self::assertSame(201, $created['status'], json_encode($created['json']));
        $t = $created['json']['testimonial'];
        self::assertNull($t['rating']);
        self::assertGreaterThanOrEqual(4.0, $t['rating_display']);
        self::assertSame([], $t['images']);
        $id = $t['id'];

        $got = $this->request('GET', "/api/testimonials/$id");
        self::assertSame(200, $got['status']);
        self::assertSame('Api Tester', $got['json']['testimonial']['author_name']);

        $patched = $this->request('PATCH', "/api/testimonials/$id", ['is_active' => false, 'rating' => 3]);
        self::assertSame(200, $patched['status']);
        self::assertFalse($patched['json']['testimonial']['is_active']);
        self::assertSame(3.0, $patched['json']['testimonial']['rating_display']);

        $list = $this->request('GET', '/api/landings/' . self::EN . '/testimonials');
        self::assertSame(200, $list['status']);
        self::assertFalse($list['json']['meta']['inherited']);
        self::assertContains($id, array_column($list['json']['data'], 'id'));

        $deleted = $this->request('DELETE', "/api/testimonials/$id");
        self::assertSame(204, $deleted['status']);
        self::assertSame(404, $this->request('GET', "/api/testimonials/$id")['status']);
        self::assertSame(404, $this->request('DELETE', "/api/testimonials/$id")['status']);
    }

    public function testValidationErrorShape(): void
    {
        $r = $this->request('POST', '/api/landings/' . self::EN . '/testimonials', ['text' => str_repeat('x', 2001), 'url' => 'nope']);
        self::assertSame(422, $r['status']);
        self::assertSame('validation_failed', $r['json']['error']['code']);
        self::assertSame(['author_name', 'text', 'url'], array_keys($r['json']['error']['fields']));
    }

    public function testInheritedListForLocalisedLandingWithoutOwn(): void
    {
        $landings = $this->request('GET', '/api/products/abforge/landings')['json']['data'];
        $inheriting = array_values(array_filter($landings, fn ($l) => $l['inherits_from_master']));
        self::assertNotEmpty($inheriting);
        $r = $this->request('GET', '/api/landings/' . $inheriting[0]['id'] . '/testimonials');
        self::assertSame(200, $r['status']);
        self::assertTrue($r['json']['meta']['inherited']);
        self::assertSame(self::EN, $r['json']['meta']['source_landing_id']);
        self::assertSame($inheriting[0]['id'], $r['json']['meta']['landing']['id']);
        self::assertNotEmpty($r['json']['data']);
    }

    public function testUnknownLandingAndBadIds(): void
    {
        self::assertSame(404, $this->request('GET', '/api/landings/999999999/testimonials')['status']);
        self::assertSame(404, $this->request('POST', '/api/landings/999999999/testimonials', ['author_name' => 'A', 'text' => 'T'])['status']);
        self::assertSame(404, $this->request('GET', '/api/testimonials/abc')['status']);
    }

    public function testReorderTestimonials(): void
    {
        $list = $this->request('GET', '/api/landings/' . self::EN . '/testimonials')['json']['data'];
        $ids = array_column($list, 'id');
        self::assertGreaterThanOrEqual(2, count($ids));
        $reversed = array_reverse($ids);
        $r = $this->request('PATCH', '/api/landings/' . self::EN . '/testimonials/reorder', ['ids' => $reversed]);
        self::assertSame(200, $r['status']);
        self::assertSame($reversed, array_column($r['json']['data'], 'id'));
        // restore original order so other tests in this run aren't affected
        $this->request('PATCH', '/api/landings/' . self::EN . '/testimonials/reorder', ['ids' => $ids]);
    }

    public function testReorderRejectsWrongIdSet(): void
    {
        $r = $this->request('PATCH', '/api/landings/' . self::EN . '/testimonials/reorder', ['ids' => [999999999]]);
        self::assertSame(422, $r['status']);
        self::assertArrayHasKey('ids', $r['json']['error']['fields']);
    }
}
