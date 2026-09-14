<?php

declare(strict_types=1);

namespace Tests\Api;

final class AiTest extends ApiTestCase
{
    public function testListProviders(): void
    {
        $r = $this->request('GET', '/api/ai/providers');
        self::assertSame(200, $r['status']);
        $ids = array_column($r['json']['data'], 'id');
        self::assertEqualsCanonicalizing(['openai', 'gemini', 'claude'], $ids);
    }

    public function testTranslate(): void
    {
        $r = $this->request('POST', '/api/ai/translate', ['provider' => 'openai', 'text' => 'Great product', 'target_country' => 'si']);
        self::assertSame(200, $r['status']);
        self::assertSame('[SI] Great product', $r['json']['text']);
    }

    public function testTranslateValidation(): void
    {
        $r = $this->request('POST', '/api/ai/translate', ['provider' => 'nope', 'text' => '', 'target_country' => 'x']);
        self::assertSame(422, $r['status']);
        self::assertArrayHasKey('provider', $r['json']['error']['fields']);
        self::assertArrayHasKey('text', $r['json']['error']['fields']);
        self::assertArrayHasKey('target_country', $r['json']['error']['fields']);
    }

    public function testAuthorName(): void
    {
        $r = $this->request('POST', '/api/ai/author-name', ['provider' => 'claude', 'country' => 'de', 'gender' => 'male']);
        self::assertSame(200, $r['status']);
        self::assertContains($r['json']['name'], ['Leo', 'Max', 'Theo']);
    }

    public function testAiEndpointsRequireSession(): void
    {
        $this->logout();
        self::assertSame(401, $this->request('GET', '/api/ai/providers')['status']);
    }
}
