<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

/** One value of a facet and how many of the matching documents have it. */
final readonly class FacetValue implements \JsonSerializable
{
    public function __construct(
        /** The filter value as the index stores it (`null` for the documents that have none). */
        public bool|int|string|null $value,
        public int $count,
    ) {}

    /** @return array{value: bool|int|string|null, count: int} */
    public function toArray(): array
    {
        return ['value' => $this->value, 'count' => $this->count];
    }

    /** @return array{value: bool|int|string|null, count: int} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
