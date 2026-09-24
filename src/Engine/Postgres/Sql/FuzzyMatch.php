<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Sql;

/**
 * @internal The fuzzy branch of one query, compiled by FuzzyQueryCompiler. Both fragments refer
 * to the sidecar table as "s" and only contain bound placeholders for user input.
 */
final readonly class FuzzyMatch
{
    public function __construct(
        /** Boolean expression for the WHERE of the fuzzy candidate CTE. */
        public string $predicate,
        /** Numeric expression (0..1) used as r_fuzzy. */
        public string $score,
    ) {}
}
