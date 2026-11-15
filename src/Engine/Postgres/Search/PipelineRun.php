<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Search;

use Fuzzphony\Engine\Postgres\Statement;

/**
 * @internal The normal search, run: strict full text, then the typo-tolerant branch as the fuzzy mode says (or browsing when there
 * is no text). The typo-tolerant branch (the per-word candidate probes and the ranked statement it ends with) is a "phase"; a run
 * made with $defer holds it unrun in `phase`, with the strict statement's rows as they are, and completed() runs it.
 */
final readonly class PipelineRun
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<Statement>            $statements
     * @param list<string>|null          $emptyQueries the stop-word tsqueries when the search asked for them already, else null
     * @param list<string>               $warnings
     * @param (\Closure(array<string, array{word: string, score: float}>): PhaseResult)|null $phase the typo-tolerant phase, given the words the typos most likely meant (Suggestion::$corrections)
     */
    public function __construct(
        public array $rows,
        public array $statements,
        public bool $usedFuzzy,
        public ?float $threshold,
        public ?string $tsquery,
        public bool $fuzzy,
        public ?array $emptyQueries,
        public bool $browse,
        public array $warnings,
        public ?RankedSearch $ranked,
        public ?\Closure $phase,
    ) {}

    /**
     * The run with its typo-tolerant phase run: the phase's rows, statements, setting and ranked statement replace the strict
     * statement's, as they always did. A run without a phase is returned as it is.
     *
     * @param array<string, array{word: string, score: float}> $corrections typed word => the word it most likely meant, whose hits rank above its look-alikes
     */
    public function completed(array $corrections = []): self
    {
        if ($this->phase === null) {
            return $this;
        }
        $phase = ($this->phase)($corrections);

        return new self(
            $phase->rows,
            [...$this->statements, ...$phase->statements],
            true,
            $phase->threshold,
            $this->tsquery,
            $this->fuzzy,
            $this->emptyQueries,
            $this->browse,
            $this->warnings,
            $phase->ranked,
            null,
        );
    }
}
