<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

/** @implements \IteratorAggregate<int, Hit> */
final readonly class SearchResult implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Hit>    $hits
     * @param list<string> $warnings Corrections applied to the search text; safe to show to users.
     */
    public function __construct(
        public array $hits,
        public int $total,
        /** True when the candidate limit was reached: display "$total+" instead of an exact number. */
        public bool $totalIsLowerBound,
        public float $tookMs,
        public bool $usedFuzzy,
        public array $warnings,
        public int $limit,
        public int $offset,
        /** The canonical form of the parsed query, e.g. "(wireless AND NOT cable)". */
        public ?string $interpretedAs = null,
    ) {}

    /** @param list<string> $warnings */
    public static function empty(int $limit, int $offset, array $warnings = [], float $tookMs = 0.0): self
    {
        return new self([], 0, false, $tookMs, false, array_values($warnings), $limit, $offset);
    }

    /** @return list<int|string> */
    public function ids(): array
    {
        return array_map(static fn(Hit $hit): int|string => $hit->id, $this->hits);
    }

    /** @return \ArrayIterator<int, Hit> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->hits);
    }

    public function count(): int
    {
        return count($this->hits);
    }

    public function page(): int
    {
        return intdiv($this->offset, $this->limit) + 1;
    }

    public function hasMore(): bool
    {
        return $this->totalIsLowerBound || $this->offset + count($this->hits) < $this->total;
    }
}
