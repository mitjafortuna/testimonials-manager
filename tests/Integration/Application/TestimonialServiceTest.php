<?php

declare(strict_types=1);

namespace Tests\Integration\Application;

use App\Application\TestimonialService;
use App\Domain\Auth\CurrentUser;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use App\Domain\Testimonial\RatingResolver;
use App\Domain\Testimonial\TestimonialValidator;
use App\Infrastructure\Repository\ImageRepository;
use App\Infrastructure\Repository\LandingRepository;
use App\Infrastructure\Repository\TestimonialRepository;
use App\Infrastructure\Storage\ImageStorage;
use Tests\Integration\DatabaseTestCase;
use Tests\Support\ImageFixtures;

final class TestimonialServiceTest extends DatabaseTestCase
{
    private TestimonialService $svc;
    private ImageRepository $imageRepo;
    private ImageStorage $imageStorage;
    private string $uploadDir;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $p = self::insert('products', ['parent_sku' => 'abforge', 'title' => 'AbForge']);
        self::insert('landings', ['id' => 1, 'product_id' => $p, 'country' => 'EN', 'is_master' => 1, 'url' => 'u/en', 'title' => 'EN', 'last_synced_at' => '2026-01-01 00:00:00']);
        self::insert('landings', ['id' => 2, 'product_id' => $p, 'country' => 'SI', 'is_master' => 0, 'url' => 'u/si', 'title' => 'SI', 'last_synced_at' => '2026-01-01 00:00:00']);
        self::insert('landings', ['id' => 3, 'product_id' => $p, 'country' => 'DE', 'is_master' => 0, 'url' => 'u/de', 'last_synced_at' => '2026-01-01 00:00:00', 'removed_at' => '2026-01-02 00:00:00']);
        $this->userId = self::insert('users', ['username' => 'admin', 'password_hash' => 'x', 'display_name' => 'Admin']);
        $user = new class ($this->userId) implements CurrentUser {
            public function __construct(private int $id)
            {
            }

            public function id(): ?int
            {
                return $this->id;
            }

            public function displayName(): ?string
            {
                return 'Admin';
            }
        };
        $this->uploadDir = sys_get_temp_dir() . '/tm-testimonial-delete-' . bin2hex(random_bytes(4));
        mkdir($this->uploadDir);
        $this->imageRepo = new ImageRepository(self::$pdo);
        $this->imageStorage = new ImageStorage($this->uploadDir, 300);
        $this->svc = new TestimonialService(
            new TestimonialRepository(self::$pdo),
            new LandingRepository(self::$pdo),
            $this->imageRepo,
            $this->imageStorage,
            new TestimonialValidator(),
            new RatingResolver(fn () => 3),
            $user,
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->uploadDir . '/*') ?: []);
        rmdir($this->uploadDir);
    }

    public function testCreatePresentsWithRatingDisplayAndAudit(): void
    {
        $t = $this->svc->create(1, ['author_name' => 'Ana', 'text' => 'Nice', 'rating' => 'random']);
        self::assertNull($t['rating']);
        self::assertSame(4.3, $t['rating_display']);
        self::assertSame([], $t['images']);
        self::assertSame($this->userId, $t['created_by']);
        self::assertSame(0, $t['sort_order']);
        self::assertTrue($t['is_active']);
        $fixed = $this->svc->create(1, ['author_name' => 'Bo', 'text' => 'Ok', 'rating' => 2]);
        self::assertSame(2.0, $fixed['rating_display']);
    }

    public function testCreateOnMissingOrRemovedLandingIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->svc->create(3, ['author_name' => 'A', 'text' => 'T']);
    }

    public function testCreateValidationErrorsBubble(): void
    {
        $this->expectException(ValidationException::class);
        $this->svc->create(1, ['text' => 'no name']);
    }

    public function testListInheritsFromMasterWhenLandingHasNone(): void
    {
        $this->svc->create(1, ['author_name' => 'EN one', 'text' => 'T']);
        $this->svc->create(1, ['author_name' => 'EN two', 'text' => 'T']);
        $own = $this->svc->listForLanding(1);
        self::assertFalse($own['meta']['inherited']);
        self::assertSame(1, $own['meta']['source_landing_id']);
        self::assertSame('EN', $own['meta']['landing']['country']);

        $si = $this->svc->listForLanding(2);
        self::assertTrue($si['meta']['inherited']);
        self::assertSame(1, $si['meta']['source_landing_id']);
        self::assertSame(2, $si['meta']['landing']['id']);
        self::assertSame(['EN one', 'EN two'], array_column($si['data'], 'author_name'));

        $this->svc->create(2, ['author_name' => 'SI own', 'text' => 'T']);
        $si = $this->svc->listForLanding(2);
        self::assertFalse($si['meta']['inherited']);
        self::assertSame(['SI own'], array_column($si['data'], 'author_name'));
    }

    public function testListOnRemovedLandingIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->svc->listForLanding(3);
    }

    public function testUpdatePartialAndDelete(): void
    {
        $t = $this->svc->create(1, ['author_name' => 'A', 'text' => 'T', 'rating' => 5]);
        $u = $this->svc->update($t['id'], ['is_active' => false]);
        self::assertFalse($u['is_active']);
        self::assertSame(5, $u['rating']);
        self::assertSame('A', $u['author_name']);
        try {
            $this->svc->update($t['id'], ['rating' => 9]);
            self::fail();
        } catch (ValidationException $e) {
            self::assertArrayHasKey('rating', $e->getFields());
        }
        $this->svc->delete($t['id']);
        $this->expectException(NotFoundException::class);
        $this->svc->get($t['id']);
    }

    public function testDeleteRemovesImageFilesFromDisk(): void
    {
        $t = $this->svc->create(1, ['author_name' => 'Img', 'text' => 'T']);
        $names = $this->imageStorage->store(ImageFixtures::png(sys_get_temp_dir()), 'png');
        $this->imageRepo->insert($t['id'], [
            'filename' => $names['filename'], 'thumb_filename' => $names['thumb_filename'],
            'mime' => 'image/png', 'size_bytes' => 100, 'width' => 10, 'height' => 10,
        ], null);
        self::assertFileExists($this->imageStorage->path($names['filename']));
        self::assertFileExists($this->imageStorage->path($names['thumb_filename']));

        $this->svc->delete($t['id']);

        self::assertFileDoesNotExist($this->imageStorage->path($names['filename']));
        self::assertFileDoesNotExist($this->imageStorage->path($names['thumb_filename']));
    }
}
