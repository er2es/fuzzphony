<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

/**
 * @internal What the highlighter needs to mark the hits that were found by typo tolerance only: the typo-tolerant
 * words of the query, the ids of the page whose strict (full-text) branch did not match, and the search's explicit
 * fuzzy_similarity (null: the similarity each word's length needs, TypoCurve). For a restricted viewer the fields it may
 * see are listed: whether the strict query matches a document is then judged on those fields only, never on the whole
 * document (a hidden field must not decide what the viewer sees).
 */
final readonly class TypoPlan
{
    /**
     * @param list<TypoTerm>   $terms
     * @param list<int|string> $ids
     * @param list<string>|null $allowedFields
     */
    public function __construct(
        public array $terms,
        public array $ids,
        public ?float $similarity = null,
        public ?array $allowedFields = null,
    ) {}
}
