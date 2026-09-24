<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

/** Thrown for developer-side misuse of the query API (unknown filter, wrong value type, ...). */
final class InvalidQuery extends \InvalidArgumentException implements FuzzphonyException
{
    /** @param list<string> $known */
    public static function unknownFilter(string $index, string $filter, array $known): self
    {
        $hint = self::closest($filter, $known);

        return new self(sprintf(
            'Index "%s" has no filter "%s".%s Known filters: %s.',
            $index,
            $filter,
            $hint !== null ? sprintf(' Did you mean "%s"?', $hint) : '',
            $known === [] ? '(none)' : implode(', ', $known),
        ));
    }

    public static function missingTenant(string $index): self
    {
        return new self(sprintf('Index "%s" requires forTenant(); none was given.', $index));
    }

    public static function unexpectedTenant(string $index): self
    {
        return new self(sprintf('Index "%s" is not tenant-scoped; forTenant() has no effect here.', $index));
    }

    /** @param list<string> $known */
    public static function unknownProfile(string $index, string $profile, array $known): self
    {
        return new self(sprintf(
            'Index "%s" has no ranking profile "%s". Known profiles: %s.',
            $index,
            $profile,
            implode(', ', $known),
        ));
    }

    /** @param list<string> $candidates */
    private static function closest(string $needle, array $candidates): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $distance = levenshtein(strtolower($needle), strtolower($candidate));
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $bestDistance <= 3 ? $best : null;
    }
}
