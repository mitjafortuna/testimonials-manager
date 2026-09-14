<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Upstream;

use App\Domain\Exception\UpstreamException;
use App\Infrastructure\Upstream\CurlLandingsApiClient;
use App\Infrastructure\Upstream\HttpTransportInterface;
use PHPUnit\Framework\TestCase;

final class RecordingTransport implements HttpTransportInterface
{
    /** @var list<array{url:string, headers:list<string>}> */
    public array $calls = [];

    /** @param list<array{status:int,body:string}> $responses */
    public function __construct(private array $responses)
    {
    }

    public function get(string $url, array $headers): array
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers];
        return array_shift($this->responses) ?? ['status' => 500, 'body' => ''];
    }
}

final class CurlLandingsApiClientTest extends TestCase
{
    /** @param list<array{status:int,body:string}> $responses */
    private function transport(array $responses): RecordingTransport
    {
        return new RecordingTransport($responses);
    }

    /** @param list<array<string,mixed>> $rows */
    private function page(array $rows, int $limit, int $offset, int $total): string
    {
        return json_encode(['data' => $rows, 'meta' => ['count' => count($rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset]], JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function row(int $id, string $cc = 'EN'): array
    {
        return ['id' => $id, 'parent_sku' => 'sku', 'country' => $cc, 'is_master' => $cc === 'EN', 'url' => "https://x/$cc", 'title' => 't', 'description' => 'd', 'image' => 'i', 'status' => 's'];
    }

    public function testPagesUntilShortPageAndSendsApiKey(): void
    {
        $t = $this->transport([
            ['status' => 200, 'body' => $this->page([$this->row(1), $this->row(2, 'SI')], 2, 0, 3)],
            ['status' => 200, 'body' => $this->page([$this->row(3, 'IT')], 2, 2, 3)],
        ]);
        $client = new CurlLandingsApiClient($t, 'https://api.test/landings.php', 'secret', 2);
        $rows = $client->fetchAll();
        self::assertSame([1, 2, 3], array_column($rows, 'id'));
        self::assertCount(2, $t->calls);
        self::assertStringContainsString('limit=2&offset=0', $t->calls[0]['url']);
        self::assertStringContainsString('limit=2&offset=2', $t->calls[1]['url']);
        self::assertContains('X-Api-Key: secret', $t->calls[0]['headers']);
        self::assertTrue($rows[0]['is_master']);
        self::assertIsInt($rows[0]['id']);
    }

    public function testNon200IsUpstreamException(): void
    {
        $client = new CurlLandingsApiClient($this->transport([['status' => 401, 'body' => 'nope']]), 'https://api.test', 'k');
        $this->expectException(UpstreamException::class);
        $client->fetchAll();
    }

    public function testInvalidJsonIsUpstreamException(): void
    {
        $client = new CurlLandingsApiClient($this->transport([['status' => 200, 'body' => '<html>']]), 'https://api.test', 'k');
        $this->expectException(UpstreamException::class);
        $client->fetchAll();
    }

    public function testPagesByMetaTotalEvenWhenUpstreamCapsTheLimit(): void
    {
        // We ask for pageSize 1000, but upstream caps it to 2 (as reflected in meta.limit)
        // and reports meta.total; paging must follow meta.total, not our own pageSize.
        $t = $this->transport([
            ['status' => 200, 'body' => $this->page([$this->row(1), $this->row(2)], 2, 0, 3)],
            ['status' => 200, 'body' => $this->page([$this->row(3)], 2, 2, 3)],
        ]);
        $client = new CurlLandingsApiClient($t, 'https://api.test/landings.php', 'secret', 1000);
        $rows = $client->fetchAll();
        self::assertSame([1, 2, 3], array_column($rows, 'id'));
        self::assertCount(2, $t->calls);
        self::assertStringContainsString('limit=1000&offset=0', $t->calls[0]['url']);
        self::assertStringContainsString('limit=1000&offset=2', $t->calls[1]['url']);
    }

    public function testPagesByShortPageWhenMetaTotalIsAbsent(): void
    {
        $body1 = json_encode(['data' => [$this->row(1), $this->row(2)], 'meta' => ['count' => 2, 'limit' => 2, 'offset' => 0]], JSON_THROW_ON_ERROR);
        $body2 = json_encode(['data' => [$this->row(3)], 'meta' => ['count' => 1, 'limit' => 2, 'offset' => 2]], JSON_THROW_ON_ERROR);
        $t = $this->transport([
            ['status' => 200, 'body' => $body1],
            ['status' => 200, 'body' => $body2],
        ]);
        $client = new CurlLandingsApiClient($t, 'https://api.test/landings.php', 'secret', 2);
        $rows = $client->fetchAll();
        self::assertSame([1, 2, 3], array_column($rows, 'id'));
        self::assertCount(2, $t->calls);
    }

    public function testConstructorRejectsNonPositivePageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CurlLandingsApiClient($this->transport([]), 'https://api.test', 'k', 0);
    }
}
