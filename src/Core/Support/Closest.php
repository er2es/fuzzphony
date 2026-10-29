<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Support;

/** @internal The candidate nearest to a mistyped name, for "Did you mean ...?" in error messages. */
final class Closest
{
    /** @param iterable<string> $candidates */
    public static function of(string $needle, iterable $candidates): ?string
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
