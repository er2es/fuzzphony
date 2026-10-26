<?php

declare(strict_types=1);

// @php-cs-fixer-ignore final_class

namespace Fuzzphony\Core\Exception;

use Fuzzphony\Core\Support\Closest;

/**
 * Thrown for developer-side misuse of the query API (unknown filter, wrong value type, ...).
 * Not final on purpose: ActorRequired extends it, so callers catching InvalidQuery also catch a refused actor.
 */
class InvalidQuery extends \InvalidArgumentException implements FuzzphonyException
{
    /** @param list<string> $known */
    public static function unknownFilter(string $index, string $filter, array $known): self
    {
        $hint = Closest::of($filter, $known);

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
}
