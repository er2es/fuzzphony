<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Support;

/**
 * Narrows a mixed value (a PDO row column, a Console argument, ...) to the
 * scalar type the caller already knows it holds, without an unchecked cast.
 */
final class Coerce
{
    public static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
