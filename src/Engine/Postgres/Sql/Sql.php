<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Sql;

use Fuzzphony\Core\Support\Identifier;

/** @internal SQL literal helpers. Only ever used with validated, developer-defined values. */
final class Sql
{
    public static function ident(string $identifier): string
    {
        return Identifier::quote($identifier);
    }

    public static function string(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public static function float(float $value): string
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException('Non-finite number in SQL.');
        }
        $formatted = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    /** PostgreSQL array literal for binding a list as one parameter: {1,2,3} / {"a","b"}. */
    public static function arrayLiteral(array $values): string
    {
        return '{' . implode(',', array_map(
            static fn (mixed $v): string => is_int($v) ? (string) $v : '"' . addcslashes((string) $v, '"\\') . '"',
            $values,
        )) . '}';
    }
}
