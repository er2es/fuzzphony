<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Search;

use Fuzzphony\Engine\Postgres\Statement;

/** @internal The typo-tolerant phase of a pipeline, run: its rows, the statement it ran, the setting that statement needed and its ranked form. */
final readonly class PhaseResult
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<Statement>            $statements
     */
    public function __construct(
        public array $rows,
        public array $statements,
        public ?float $threshold,
        public RankedSearch $ranked,
    ) {}
}
