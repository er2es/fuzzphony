<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Engine\Postgres\Schema\Types;
use PHPUnit\Framework\TestCase;

final class TypesTest extends TestCase
{
    public function testIdTypes(): void
    {
        self::assertSame('bigint', Types::id(IdType::Int));
        self::assertSame('uuid', Types::id(IdType::Uuid));
        self::assertSame('text', Types::id(IdType::String));
    }

    public function testFilterTypes(): void
    {
        self::assertSame(
            ['boolean', 'bigint', 'double precision', 'text', 'date', 'timestamptz'],
            array_map(Types::filter(...), FilterType::cases()),
        );
    }

    public function testCompatibleSourceTypes(): void
    {
        self::assertSame(['boolean'], Types::compatible(FilterType::Bool));
        self::assertSame(['smallint', 'integer', 'bigint'], Types::compatible(FilterType::Int));
        self::assertSame(['real', 'double precision', 'numeric', 'smallint', 'integer', 'bigint'], Types::compatible(FilterType::Float));
        self::assertSame(['text', 'character varying', 'character', 'citext', 'uuid'], Types::compatible(FilterType::String));
        self::assertSame(['date', 'timestamp without time zone', 'timestamp with time zone'], Types::compatible(FilterType::Date));
        self::assertSame(['timestamp without time zone', 'timestamp with time zone', 'date'], Types::compatible(FilterType::DateTime));
    }
}
