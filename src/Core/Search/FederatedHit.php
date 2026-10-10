<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

/** A hit of a federated search: the index it came from, the hit itself, and its merged score. */
final readonly class FederatedHit
{
    public function __construct(
        public string $index,
        /** The hit as its index returned it: its own id, score, breakdown and highlights. */
        public Hit $hit,
        /** The reciprocal-rank-fusion score the merged list is ordered by: weight / (60 + rank in its index). Comparable across indexes, unlike `$hit->score`. */
        public float $score,
    ) {}
}
