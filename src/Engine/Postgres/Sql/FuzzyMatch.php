<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Sql;

/**
 * @internal The fuzzy branch of one query, compiled by FuzzyQueryCompiler. The per-word values
 * are columns of the statement's one-row "q" CTE (the only place with bound placeholders);
 * predicate and score read them as q.<column> and the sidecar table as "s".
 */
final readonly class FuzzyMatch
{
    public function __construct(
        /** Boolean expression for the WHERE of the fuzzy candidate CTE. */
        public string $predicate,
        /** Numeric expression (0..1) used as r_fuzzy. */
        public string $score,
        /** @var list<string> select-list items for the q CTE, e.g. "fuzzphony_norm(:p3) AS fn0" */
        public array $columns,
    ) {}
}
