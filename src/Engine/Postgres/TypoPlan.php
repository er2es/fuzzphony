<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

/**
 * @internal What the highlighter needs to mark the hits that were found by typo tolerance only: the typo-tolerant
 * words of the query, the ids of the page whose strict (full-text) branch did not match, and the search's explicit
 * fuzzy_similarity (null: the similarity each word's length needs, TypoCurve).
 */
final readonly class TypoPlan
{
    /**
     * @param list<TypoTerm>   $terms
     * @param list<int|string> $ids
     */
    public function __construct(
        public array $terms,
        public array $ids,
        public ?float $similarity = null,
    ) {}
}
