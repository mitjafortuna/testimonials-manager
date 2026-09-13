<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($this->file, "# comment\nFOO_TEST=bar\nQUOTED=\"hello world\"\nEMPTY=\n");
        putenv('PRESET_TEST=keep');
        file_put_contents($this->file, "PRESET_TEST=override\n", FILE_APPEND);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        putenv('FOO_TEST');
        putenv('QUOTED');
        putenv('EMPTY');
        putenv('PRESET_TEST');
    }

    public function testLoadsKeyValuePairs(): void
    {
        Env::load($this->file);
        self::assertSame('bar', Env::get('FOO_TEST'));
        self::assertSame('hello world', Env::get('QUOTED'));
        self::assertSame('', Env::get('EMPTY'));
    }

    public function testDoesNotOverrideExistingVariables(): void
    {
        Env::load($this->file);
        self::assertSame('keep', Env::get('PRESET_TEST'));
    }

    public function testMissingFileIsIgnored(): void
    {
        Env::load('/nonexistent/.env');
        self::assertSame('dflt', Env::get('NOPE_TEST', 'dflt'));
    }
}
