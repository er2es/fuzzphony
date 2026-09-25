<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\IdType;
use PHPUnit\Framework\TestCase;

final class IdTypeTest extends TestCase
{
    public function testSqlTypes(): void
    {
        self::assertSame('bigint', IdType::Int->sqlType());
        self::assertSame('uuid', IdType::Uuid->sqlType());
        self::assertSame('text', IdType::String->sqlType());
    }

    public function testCastConvertsIntIdsButLeavesOthersAsStrings(): void
    {
        self::assertSame(42, IdType::Int->cast('42'));
        self::assertSame('9b1d', IdType::Uuid->cast('9b1d'));
        self::assertSame('abc', IdType::String->cast('abc'));
    }
}
