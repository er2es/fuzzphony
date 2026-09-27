<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Support;

/**
 * @internal SQL identifier validation. Fuzzphony never interpolates user input into SQL;
 * identifiers come from definitions and are validated here before use.
 */
final class Identifier
{
    private const string NAME = '/^[a-z_][a-z0-9_]{0,47}$/';
    private const string COLUMN = '/^[A-Za-z_][A-Za-z0-9_]{0,62}$/';
    private const string TABLE = '/^[A-Za-z_][A-Za-z0-9_]{0,62}(\.[A-Za-z_][A-Za-z0-9_]{0,62})?$/';

    public static function isName(string $value): bool
    {
        return preg_match(self::NAME, $value) === 1;
    }

    public static function isColumn(string $value): bool
    {
        return preg_match(self::COLUMN, $value) === 1;
    }

    public static function isTable(string $value): bool
    {
        return preg_match(self::TABLE, $value) === 1;
    }

    /** Quotes a (possibly schema-qualified) identifier: public.product -> "public"."product". */
    public static function quote(string $identifier): string
    {
        return implode('.', array_map(
            static fn(string $part): string => '"' . str_replace('"', '""', $part) . '"',
            explode('.', $identifier),
        ));
    }

    public static function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }
}
