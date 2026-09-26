<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Exception\InvalidQuery;

enum FilterType: string
{
    case Bool = 'bool';
    case Int = 'int';
    case Float = 'float';
    case String = 'string';
    case Date = 'date';
    case DateTime = 'datetime';

    public static function fromPhpType(string $type): ?self
    {
        return match (ltrim(strtolower($type), '?\\')) {
            'bool' => self::Bool,
            'int' => self::Int,
            'float' => self::Float,
            'string' => self::String,
            'datetimeinterface', 'datetimeimmutable', 'datetime' => self::DateTime,
            default => null,
        };
    }

    /**
     * Converts a developer-supplied filter value into a bindable scalar.
     *
     * @throws InvalidQuery when the value does not fit the declared type
     */
    public function normalize(mixed $value, string $filter): bool|int|float|string
    {
        return match ($this) {
            self::Bool => is_bool($value) ? $value : throw self::mismatch($filter, 'bool', $value),
            self::Int => is_int($value) ? $value
                : (is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : throw self::mismatch($filter, 'int', $value)),
            self::Float => is_int($value) || is_float($value) ? (float) $value
                : (is_string($value) && is_numeric($value) ? (float) $value : throw self::mismatch($filter, 'float', $value)),
            self::String => is_string($value) || $value instanceof \Stringable ? (string) $value : throw self::mismatch($filter, 'string', $value),
            self::Date => self::toDate($value, 'Y-m-d') ?? throw self::mismatch($filter, 'date (DateTimeInterface or Y-m-d string)', $value),
            self::DateTime => self::toDate($value, \DateTimeInterface::ATOM) ?? throw self::mismatch($filter, 'datetime (DateTimeInterface or ISO-8601 string)', $value),
        };
    }

    private static function toDate(mixed $value, string $format): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format($format);
        }
        if (is_string($value) && $value !== '') {
            try {
                return (new \DateTimeImmutable($value))->format($format);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    private static function mismatch(string $filter, string $expected, mixed $value): InvalidQuery
    {
        return new InvalidQuery(sprintf('Filter "%s" expects a %s value, got %s.', $filter, $expected, get_debug_type($value)));
    }
}
