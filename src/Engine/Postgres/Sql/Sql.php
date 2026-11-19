<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Sql;

use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Support\Identifier;

/** @internal SQL literal helpers. Only ever used with validated, developer-defined values. */
final class Sql
{
    public static function ident(string $identifier): string
    {
        return Identifier::quote($identifier);
    }

    /**
     * A plain literal reads a backslash as itself only with standard_conforming_strings = on (the default since 9.1); a value that
     * has one is written as an escape string (E'...', backslashes doubled), which reads the same under either setting.
     */
    public static function string(string $value): string
    {
        $quoted = str_replace("'", "''", $value);

        return str_contains($value, '\\') ? "E'" . str_replace('\\', '\\\\', $quoted) . "'" : "'" . $quoted . "'";
    }

    public static function float(float $value): string
    {
        if (!is_finite($value)) {
            throw new InvalidArgument('Non-finite number in SQL.');
        }
        $formatted = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    /**
     * PostgreSQL array literal for binding a list as one parameter: {1,2,3} / {"a","b"}.
     *
     * @param list<int|string> $values
     */
    public static function arrayLiteral(array $values): string
    {
        return '{' . implode(',', array_map(
            static fn(int|string $v): string => is_int($v) ? (string) $v : '"' . addcslashes($v, '"\\') . '"',
            $values,
        )) . '}';
    }
}
