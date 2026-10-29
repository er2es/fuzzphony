<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Search;

use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Search\FacetValue;
use Fuzzphony\Engine\Postgres\Statement;

/**
 * @internal Everything a search is left with once its statements have run (strict, typo-tolerant, relaxed, exact total, facets,
 * suggestion): what SearchRunner turns into hits, highlights and a SearchResult.
 */
final readonly class Completion
{
    /**
     * @param list<Statement>                      $statements
     * @param list<string>                         $warnings
     * @param list<array<string, mixed>>           $rows         the hits of the page (the meta row of an empty page is gone)
     * @param array<string, list<FacetValue>>      $facets
     * @param array{from: string, to: string}|null $rescue
     */
    public function __construct(
        public PipelineRun $run,
        public array $statements,
        public array $warnings,
        public ?float $threshold,
        public ?Node $root,
        public array $rows,
        public ?string $tsquery,
        public int $total,
        public bool $capped,
        public array $facets,
        public ?string $didYouMean,
        public ?array $rescue,
        public bool $relaxed,
    ) {}
}
