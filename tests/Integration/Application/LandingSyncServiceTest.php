<?php

declare(strict_types=1);

namespace Tests\Integration\Application;

use App\Application\LandingSyncService;
use App\Domain\Exception\UpstreamException;
use App\Infrastructure\Repository\LandingRepository;
use App\Infrastructure\Repository\ProductRepository;
use App\Infrastructure\Repository\SyncRunRepository;
use App\Infrastructure\Upstream\FixtureLandingsApiClient;
use App\Infrastructure\Upstream\LandingsApiClientInterface;
use App\Support\Clock;
use Tests\Integration\DatabaseTestCase;

final class LandingSyncServiceTest extends DatabaseTestCase
{
    /** @var list<array<string,mixed>> */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = FixtureLandingsApiClient::fromFile(dirname(__DIR__, 3) . '/tests/fixtures/landings.json')->fetchAll();
    }

    private function service(LandingsApiClientInterface $client): LandingSyncService
    {
        $clock = new class () implements Clock {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-09-13 10:00:00', new \DateTimeZone('UTC'));
            }
        };

        return new LandingSyncService(
            self::$pdo,
            $client,
            new ProductRepository(self::$pdo),
            new LandingRepository(self::$pdo),
            new SyncRunRepository(self::$pdo),
            $clock,
        );
    }

    public function testFirstRunImportsEverything(): void
    {
        $result = $this->service(new FixtureLandingsApiClient($this->fixture))->run();
        self::assertSame(170, $result['added']);
        self::assertSame(0, $result['updated']);
        self::assertSame(0, $result['removed']);
        self::assertSame(10, (int) self::$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
        self::assertSame(170, (int) self::$pdo->query('SELECT COUNT(*) FROM landings')->fetchColumn());
        $master = self::$pdo->query("SELECT p.title FROM products p WHERE p.parent_sku = 'abforge'")->fetchColumn();
        self::assertSame('Strengthen your abs with smart rebound power!', $master);
        self::assertSame('ok', (new SyncRunRepository(self::$pdo))->last()['status']);
    }

    public function testSecondRunIsIdempotent(): void
    {
        $this->service(new FixtureLandingsApiClient($this->fixture))->run();
        $result = $this->service(new FixtureLandingsApiClient($this->fixture))->run();
        self::assertSame(['added' => 0, 'updated' => 0, 'removed' => 0], array_intersect_key($result, ['added' => 1, 'updated' => 1, 'removed' => 1]));
    }

    public function testResyncKeepsIdsAndTestimonials(): void
    {
        $this->service(new FixtureLandingsApiClient($this->fixture))->run();
        $landingId = $this->fixture[5]['id'];
        $testimonialId = self::insert('testimonials', ['landing_id' => $landingId, 'author_name' => 'A', 'text' => 'T', 'rating' => 5, 'gender' => 'male', 'sort_order' => 0]);

        $changed = $this->fixture;
        $changed[5]['url'] = 'https://changed.example/x';
        $removed = array_pop($changed);
        $changed[] = ['id' => 999999, 'parent_sku' => 'newprod', 'country' => 'EN', 'is_master' => true, 'url' => 'https://n', 'title' => 'New', 'description' => null, 'image' => null, 'status' => null];

        $result = $this->service(new FixtureLandingsApiClient($changed))->run();
        self::assertSame(1, $result['added']);
        self::assertSame(1, $result['updated']);
        self::assertSame(1, $result['removed']);

        self::assertSame('https://changed.example/x', self::$pdo->query("SELECT url FROM landings WHERE id = $landingId")->fetchColumn());
        self::assertSame($landingId, (int) self::$pdo->query("SELECT landing_id FROM testimonials WHERE id = $testimonialId")->fetchColumn());
        self::assertNotNull(self::$pdo->query("SELECT removed_at FROM landings WHERE id = {$removed['id']}")->fetchColumn());
        self::assertSame(11, (int) self::$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
    }

    public function testUpstreamFailureIsRecordedAndRethrown(): void
    {
        $client = new class () implements LandingsApiClientInterface {
            public function fetchAll(): array
            {
                throw new UpstreamException('down');
            }
        };

        try {
            $this->service($client)->run();
            self::fail('expected UpstreamException');
        } catch (UpstreamException) {
        }
        $last = (new SyncRunRepository(self::$pdo))->last();
        self::assertSame('failed', $last['status']);
        self::assertSame('down', $last['error_message']);
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM landings')->fetchColumn());
    }
}
