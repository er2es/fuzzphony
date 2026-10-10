<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

/** @implements \IteratorAggregate<int, FederatedHit> */
final readonly class FederatedResult implements \IteratorAggregate, \Countable
{
    /**
     * @param list<FederatedHit>          $hits     the requested page of the merged list
     * @param array<string, SearchResult> $results  each index's own result (its first offset + limit hits, facets, didYouMean, ...), keyed by index name
     * @param list<string>                $warnings those of every index, each prefixed with the index name. Plain text, not HTML.
     */
    public function __construct(
        public array $hits,
        public array $results,
        /** The sum of the indexes' totals. */
        public int $total,
        /** True when an index hit its candidate limit: display "$total+". */
        public bool $totalIsLowerBound,
        public float $tookMs,
        public array $warnings,
        public int $limit,
        public int $offset,
    ) {}

    /** @return \ArrayIterator<int, FederatedHit> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->hits);
    }

    public function count(): int
    {
        return count($this->hits);
    }

    public function hasMore(): bool
    {
        return $this->totalIsLowerBound || $this->offset + count($this->hits) < $this->total;
    }
}
