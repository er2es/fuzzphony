<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Ranking;

use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Support\Closest;

/**
 * @internal Reads the options of one threshold group (`thresholds.typo`, ...) from an array: unknown keys get a
 * "did you mean", values are coerced by one rule (numeric strings like "0.1" and "500" count, "yes"/"no" for booleans),
 * and every problem of the group goes into ONE InvalidDefinition.
 */
final class ThresholdOptions
{
    /**
     * @param array<string, string>        $types     option => float | ?float | int | ?int | bool | mode
     * @param array<array-key, mixed>      $overrides
     * @param array<string, mixed>         $current   the present value of every option
     *
     * @return array<string, mixed> the options, with the overrides applied
     */
    public static function apply(string $group, array $types, array $overrides, array $current): array
    {
        $problems = [];
        foreach ($overrides as $key => $value) {
            $key = (string) $key;
            if (!isset($types[$key])) {
                $suggestion = Closest::of($key, array_keys($types));
                $problems[] = sprintf('Unknown option "%s".%s Allowed: %s.', $key, $suggestion !== null ? sprintf(' Did you mean "%s"?', $suggestion) : '', implode(', ', array_keys($types)));
                continue;
            }
            $coerced = self::coerce($types[$key], $value);
            if ($coerced === self::INVALID) {
                $problems[] = sprintf('"%s" must be %s.', $key, self::describe($types[$key]));
                continue;
            }
            $current[$key] = $coerced;
        }
        if ($problems !== []) {
            throw new InvalidDefinition('thresholds.' . $group, $problems);
        }

        return $current;
    }

    private const string INVALID = "\0invalid";

    private static function coerce(string $type, mixed $value): mixed
    {
        $nullable = str_starts_with($type, '?');
        if ($value === null) {
            return $nullable ? null : self::INVALID;
        }

        return match (ltrim($type, '?')) {
            'float' => is_numeric($value) ? (float) $value : self::INVALID,
            'int' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? (int) $value : self::INVALID,
            'bool' => self::bool($value),
            default => $value instanceof FuzzyMode ? $value : (FuzzyMode::tryFrom(is_string($value) ? $value : '') ?? self::INVALID),
        };
    }

    private static function describe(string $type): string
    {
        return match (ltrim($type, '?')) {
            'float' => 'a number',
            'int' => 'an integer',
            'bool' => 'a boolean',
            default => 'one of: ' . implode(', ', array_map(static fn(FuzzyMode $m): string => $m->value, FuzzyMode::cases())),
        };
    }

    /** true / false, also as 1 / 0 or "true" / "false" / "yes" / "no" (command-line overrides are strings). */
    private static function bool(mixed $value): mixed
    {
        $bool = match (true) {
            is_bool($value) => $value,
            $value === '' => null, // filter_var() reads an empty string as false
            is_int($value), is_string($value) => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            default => null,
        };

        return $bool ?? self::INVALID;
    }
}
