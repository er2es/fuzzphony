<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

use Fuzzphony\Core\Definition\IndexDefinition;

/**
 * Decides whether an actor may search an index and which fields of it. Throws AccessDenied when the index is
 * not granted and RateLimited when the actor searched too often; otherwise returns what the actor may see.
 * $charge = false does not consume a rate-limit token (a dry run such as explain()); authorization ignores it.
 */
interface SearchGuard
{
    public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access;
}
