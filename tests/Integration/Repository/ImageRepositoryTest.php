<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\ImageRepository;
use Tests\Integration\DatabaseTestCase;

final class ImageRepositoryTest extends DatabaseTestCase
{
    private ImageRepository $repo;
    private int $t1;
    private int $t2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ImageRepository(self::$pdo);
        $p = self::insert('products', ['parent_sku' => 'abforge', 'title' => 'AbForge']);
        self::insert('landings', ['id' => 1, 'product_id' => $p, 'country' => 'EN', 'is_master' => 1, 'url' => 'u', 'last_synced_at' => '2026-01-01 00:00:00']);
        $this->t1 = self::insert('testimonials', ['landing_id' => 1, 'author_name' => 'A', 'text' => 'T', 'gender' => 'unisex', 'sort_order' => 0]);
        $this->t2 = self::insert('testimonials', ['landing_id' => 1, 'author_name' => 'B', 'text' => 'T', 'gender' => 'unisex', 'sort_order' => 1]);
    }

    /** @return array{filename:string,thumb_filename:string,mime:string,size_bytes:int,width:int,height:int} */
    private function data(string $n): array
    {
        return ['filename' => "$n.jpg", 'thumb_filename' => "{$n}_thumb.jpg", 'mime' => 'image/jpeg', 'size_bytes' => 100, 'width' => 10, 'height' => 5];
    }

    public function testInsertListDelete(): void
    {
        $a = $this->repo->insert($this->t1, $this->data('a'), null);
        $b = $this->repo->insert($this->t1, $this->data('b'), null);
        $c = $this->repo->insert($this->t2, $this->data('c'), null);
        $map = $this->repo->listByTestimonialIds([$this->t1, $this->t2, 999]);
        self::assertSame([$a, $b], array_column($map[$this->t1], 'id'));
        self::assertSame([0, 1], array_column($map[$this->t1], 'sort_order'));
        self::assertSame([$c], array_column($map[$this->t2], 'id'));
        self::assertArrayNotHasKey(999, $map);
        self::assertSame([], $this->repo->listByTestimonialIds([]));
        self::assertSame('a.jpg', $this->repo->find($a)['filename']);
        self::assertNull($this->repo->find(999999));
        self::assertTrue($this->repo->delete($a));
        self::assertFalse($this->repo->delete($a));
    }

    public function testCascadeOnTestimonialDelete(): void
    {
        $this->repo->insert($this->t1, $this->data('a'), null);
        self::$pdo->exec("DELETE FROM testimonials WHERE id = {$this->t1}");
        self::assertSame([], $this->repo->listByTestimonialIds([$this->t1]));
    }
}
