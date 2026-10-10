<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

/** One value of a facet and how many of the matching documents have it. */
final readonly class FacetValue
{
    public function __construct(
        /** The filter value as the index stores it (`null` for the documents that have none). */
        public bool|int|string|null $value,
        public int $count,
    ) {}
}
