<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IdType;

/** @internal PostgreSQL types of document ids and filter columns. */
final class Types
{
    public static function id(IdType $type): string
    {
        return match ($type) {
            IdType::Int => 'bigint',
            IdType::Uuid => 'uuid',
            IdType::String => 'text',
        };
    }

    public static function filter(FilterType $type): string
    {
        return match ($type) {
            FilterType::Bool => 'boolean',
            FilterType::Int => 'bigint',
            FilterType::Float => 'double precision',
            FilterType::String => 'text',
            FilterType::Date => 'date',
            FilterType::DateTime => 'timestamptz',
        };
    }

    /**
     * Source column types that can back this filter without a lossy cast.
     *
     * @return list<string>
     */
    public static function compatible(FilterType $type): array
    {
        return match ($type) {
            FilterType::Bool => ['boolean'],
            FilterType::Int => ['smallint', 'integer', 'bigint'],
            FilterType::Float => ['real', 'double precision', 'numeric', 'smallint', 'integer', 'bigint'],
            FilterType::String => ['text', 'character varying', 'character', 'citext', 'uuid'],
            FilterType::Date => ['date', 'timestamp without time zone', 'timestamp with time zone'],
            FilterType::DateTime => ['timestamp without time zone', 'timestamp with time zone', 'date'],
        };
    }
}
