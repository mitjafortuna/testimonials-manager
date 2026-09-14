<?php

declare(strict_types=1);

namespace Tests\Api;

use Tests\Support\ImageFixtures;

final class ImagesTest extends ApiTestCase
{
    private const EN = 61763;   // abforge master (seed)

    private function newTestimonial(): int
    {
        $r = $this->request('POST', '/api/landings/' . self::EN . '/testimonials', ['author_name' => 'Img Tester', 'text' => 'with images']);
        self::assertSame(201, $r['status']);
        return $r['json']['testimonial']['id'];
    }

    public function testUploadListServeDelete(): void
    {
        $id = $this->newTestimonial();
        $tmp = sys_get_temp_dir();
        $r = $this->upload("/api/testimonials/$id/images", ['images[0]' => ImageFixtures::png($tmp, 800, 400), 'images[1]' => ImageFixtures::jpeg($tmp)]);
        self::assertSame(201, $r['status'], json_encode($r['json']));
        $images = $r['json']['images'];
        self::assertCount(2, $images);
        self::assertSame([0, 1], array_column($images, 'sort_order'));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', $images[0]['filename']);

        $t = $this->request('GET', "/api/testimonials/$id")['json']['testimonial'];
        self::assertCount(2, $t['images']);

        $list = $this->request('GET', '/api/landings/' . self::EN . '/testimonials')['json']['data'];
        $mine = array_values(array_filter($list, fn ($x) => $x['id'] === $id))[0];
        self::assertCount(2, $mine['images']);

        $media = $this->raw('/media/' . $images[0]['thumb_filename']);
        self::assertSame(200, $media['status']);
        self::assertSame('image/png', $media['content_type']);
        $size = getimagesizefromstring($media['body']);
        self::assertIsArray($size);
        self::assertSame(300, $size[0]);

        self::assertSame(204, $this->request('DELETE', '/api/images/' . $images[0]['id'])['status']);
        self::assertSame(404, $this->raw('/media/' . $images[0]['thumb_filename'])['status']);
        self::assertSame(404, $this->request('DELETE', '/api/images/' . $images[0]['id'])['status']);
        $this->request('DELETE', "/api/testimonials/$id");
    }

    public function testUploadWithConvertWebpAndCrop(): void
    {
        $id = $this->newTestimonial();
        $r = $this->upload("/api/testimonials/$id/images", ['images[0]' => ImageFixtures::jpeg(sys_get_temp_dir(), 800, 400)], ['convert_webp' => '1', 'crop' => 'square']);
        self::assertSame(201, $r['status'], json_encode($r['json']));
        $img = $r['json']['images'][0];
        self::assertStringEndsWith('.webp', $img['filename']);
        self::assertSame(400, $img['width']);
        self::assertSame(400, $img['height']);

        $media = $this->raw('/media/' . $img['filename']);
        self::assertSame('image/webp', $media['content_type']);
        $this->request('DELETE', "/api/testimonials/$id");
    }

    public function testRejectsInvalidCropMode(): void
    {
        $id = $this->newTestimonial();
        $r = $this->upload("/api/testimonials/$id/images", ['images[0]' => ImageFixtures::jpeg(sys_get_temp_dir())], ['crop' => 'circle']);
        self::assertSame(422, $r['status'], json_encode($r['json']));
        self::assertArrayHasKey('crop', $r['json']['error']['fields']);
        $this->request('DELETE', "/api/testimonials/$id");
    }

    public function testRejectsNonImageAndEmptyBatch(): void
    {
        $id = $this->newTestimonial();
        $r = $this->upload("/api/testimonials/$id/images", ['images[0]' => ImageFixtures::text(sys_get_temp_dir())]);
        self::assertSame(415, $r['status']);
        self::assertSame('unsupported_media_type', $r['json']['error']['code']);
        $r = $this->upload("/api/testimonials/$id/images", []);
        self::assertSame(422, $r['status']);
        self::assertArrayHasKey('images', $r['json']['error']['fields']);
        $r = $this->upload('/api/testimonials/999999999/images', ['images[0]' => ImageFixtures::png(sys_get_temp_dir())]);
        self::assertSame(404, $r['status']);
        $this->request('DELETE', "/api/testimonials/$id");
    }

    public function testMediaRejectsUnsafeNames(): void
    {
        self::assertSame(404, $this->raw('/media/..%2F..%2F.env')['status']);
        self::assertSame(404, $this->raw('/media/nope.jpg')['status']);
    }

    public function testReorderImages(): void
    {
        $id = $this->newTestimonial();
        $tmp = sys_get_temp_dir();
        $up = $this->upload("/api/testimonials/$id/images", ['images[0]' => ImageFixtures::png($tmp), 'images[1]' => ImageFixtures::jpeg($tmp)]);
        $ids = array_column($up['json']['images'], 'id');
        $reversed = array_reverse($ids);
        $r = $this->request('PATCH', "/api/testimonials/$id/images/reorder", ['ids' => $reversed]);
        self::assertSame(200, $r['status']);
        self::assertSame($reversed, array_column($r['json']['images'], 'id'));
        $this->request('DELETE', "/api/testimonials/$id");
    }

    /** @return array{status:int,content_type:string,body:string} */
    private function raw(string $path): array
    {
        $ch = curl_init($this->baseUrl() . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $this->cookieJarPath(), CURLOPT_COOKIEJAR => $this->cookieJarPath()]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return ['status' => $status, 'content_type' => $ct, 'body' => $body];
    }
}
