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

    public function testReorderRejectsAMismatchedIdSet(): void
    {
        $t1 = $this->svc->create(1, ['author_name' => 'A', 'text' => 'a']);
        $t2 = $this->svc->create(1, ['author_name' => 'B', 'text' => 'b']);
        $this->expectException(ValidationException::class);
        $this->svc->reorder(1, [$t1['id']]);   // missing $t2['id']
    }

    public function testReorderAppliesTheGivenOrder(): void
    {
        $t1 = $this->svc->create(1, ['author_name' => 'A', 'text' => 'a']);
        $t2 = $this->svc->create(1, ['author_name' => 'B', 'text' => 'b']);
        $res = $this->svc->reorder(1, [$t2['id'], $t1['id']]);
        self::assertSame([$t2['id'], $t1['id']], array_column($res['data'], 'id'));
    }

    public function testCopyPreviewAndCopyAppend(): void
    {
        $this->svc->create(1, ['author_name' => 'EN one', 'text' => 'T']);
        $this->svc->create(1, ['author_name' => 'EN two', 'text' => 'T']);
        $this->svc->create(2, ['author_name' => 'SI existing', 'text' => 'T']);

        $preview = $this->svc->copyPreview(2, 1, 'append');
        self::assertSame('EN', $preview['source']['country']);
        self::assertSame(2, $preview['will_add']);
        self::assertSame(0, $preview['will_remove']);

        $res = $this->svc->copy(2, 1, 'append');
        self::assertSame(['SI existing', 'EN one', 'EN two'], array_column($res['data'], 'author_name'));
    }

    public function testCopyReplaceRemovesExistingAndTheirImages(): void
    {
        $this->svc->create(1, ['author_name' => 'EN one', 'text' => 'T']);
        $old = $this->svc->create(2, ['author_name' => 'SI old', 'text' => 'T']);
        $names = $this->imageStorage->store(ImageFixtures::png(sys_get_temp_dir()), 'png');
        $this->imageRepo->insert($old['id'], [
            'filename' => $names['filename'], 'thumb_filename' => $names['thumb_filename'],
            'mime' => 'image/png', 'size_bytes' => 100, 'width' => 10, 'height' => 10,
        ], null);

        $this->svc->copy(2, 1, 'replace');

        self::assertFileDoesNotExist($this->imageStorage->path($names['filename']));
        $res = $this->svc->listForLanding(2);
        self::assertSame(['EN one'], array_column($res['data'], 'author_name'));
    }

    public function testCopyRejectsSameLandingAndBadMode(): void
    {
        try {
            $this->svc->copy(1, 1, 'append');
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('source_landing_id', $e->getFields());
        }
        try {
            $this->svc->copy(2, 1, 'overwrite');
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('mode', $e->getFields());
        }
    }

    public function testBulkUpdateActivateAndDelete(): void
    {
        $a = $this->svc->create(1, ['author_name' => 'A', 'text' => 'a', 'is_active' => false]);
        $b = $this->svc->create(1, ['author_name' => 'B', 'text' => 'b', 'is_active' => false]);
        $c = $this->svc->create(1, ['author_name' => 'C', 'text' => 'c']);

        $res = $this->svc->bulkUpdate(1, [$a['id'], $b['id']], 'activate');
        $byId = array_column($res['data'], null, 'id');
        self::assertTrue($byId[$a['id']]['is_active']);
        self::assertTrue($byId[$b['id']]['is_active']);

        $res = $this->svc->bulkUpdate(1, [$c['id']], 'delete');
        self::assertCount(2, $res['data']);
    }

    public function testBulkUpdateRejectsUnknownAction(): void
    {
        $t = $this->svc->create(1, ['author_name' => 'A', 'text' => 'a']);
        $this->expectException(ValidationException::class);
        $this->svc->bulkUpdate(1, [$t['id']], 'archive');
    }

    public function testBulkUpdateIgnoresIdsFromOtherLandings(): void
    {
        $mine = $this->svc->create(1, ['author_name' => 'Mine', 'text' => 'a']);
        $other = $this->svc->create(2, ['author_name' => 'Other', 'text' => 'a']);
        $this->svc->bulkUpdate(1, [$mine['id'], $other['id']], 'deactivate');
        self::assertFalse($this->svc->get($mine['id'])['is_active']);
        self::assertTrue($this->svc->get($other['id'])['is_active']);   // untouched
    }
}
