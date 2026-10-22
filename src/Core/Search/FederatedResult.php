<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

use Fuzzphony\Core\Ranking\Thresholds;

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

    /**
     * Whether hits follow this page among the ones the search ranked: false at the last page of the candidates, even when
     * `totalIsLowerBound` says there are more matches than candidates (a page past them is empty, see
     * Thresholds::$candidateLimit). The hits that can be read end at 10 000 of an index (Thresholds::MAX_CANDIDATE_LIMIT, also with
     * exactCounts(), whose total counts every match), and a federated search reads at most FederatedSearch::MAX_READ hits of every
     * index, so there is no next page past those (limit() would refuse it); an empty page has no more either.
     */
    public function hasMore(): bool
    {
        $end = $this->offset + count($this->hits);

        return $this->hits !== [] && $end < $this->total && $end < Thresholds::MAX_CANDIDATE_LIMIT && $this->offset + $this->limit < FederatedSearch::MAX_READ;
    }
}
