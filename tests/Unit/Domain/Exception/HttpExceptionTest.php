<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exception;

use App\Domain\Exception\ConflictException;
use App\Domain\Exception\ForbiddenException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\UnauthorizedException;
use App\Domain\Exception\UpstreamException;
use App\Domain\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class HttpExceptionTest extends TestCase
{
    public function testSubclassesCarryStatusAndCode(): void
    {
        self::assertSame([404, 'not_found'], [(new NotFoundException('x'))->getStatus(), (new NotFoundException('x'))->getErrorCode()]);
        self::assertSame([401, 'unauthorized'], [(new UnauthorizedException())->getStatus(), (new UnauthorizedException())->getErrorCode()]);
        self::assertSame([403, 'forbidden'], [(new ForbiddenException())->getStatus(), (new ForbiddenException())->getErrorCode()]);
        self::assertSame([409, 'conflict'], [(new ConflictException('busy'))->getStatus(), (new ConflictException('busy'))->getErrorCode()]);
        self::assertSame([502, 'upstream_error'], [(new UpstreamException('down'))->getStatus(), (new UpstreamException('down'))->getErrorCode()]);
    }

    public function testValidationExceptionCarriesFields(): void
    {
        $e = new ValidationException(['text' => 'Required']);
        self::assertSame(422, $e->getStatus());
        self::assertSame('validation_failed', $e->getErrorCode());
        self::assertSame(['text' => 'Required'], $e->getFields());
        self::assertSame('Validation failed', $e->getMessage());
    }
}
