<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Container;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function testResolvesFactoryOnceAndCaches(): void
    {
        $c = new Container();
        $calls = 0;
        $c->set('svc', function () use (&$calls) {
            $calls++;
            return new \stdClass();
        });
        self::assertSame($c->get('svc'), $c->get('svc'));
        self::assertSame(1, $calls);
    }

    public function testFactoryReceivesContainer(): void
    {
        $c = new Container();
        $c->set('dep', fn () => new \ArrayObject(['x']));
        $c->set('svc', fn (Container $c) => (object) ['dep' => $c->get('dep')]);
        self::assertInstanceOf(\ArrayObject::class, $c->get('svc')->dep);
    }

    public function testUnknownIdThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Container())->get('nope');
    }

    public function testHas(): void
    {
        $c = new Container();
        self::assertFalse($c->has('a'));
        $c->set('a', fn () => new \stdClass());
        self::assertTrue($c->has('a'));
    }
}
