<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Search;

use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Engine\Postgres\Statement;

/**
 * @internal The empty-result relaxation of a search that found nothing: the probe, and the search without the words that match
 * nothing on their own. `warning` (the answer's note about the ignored words) is set only when the relaxed search found something and so is the answer.
 */
final readonly class RelaxAttempt
{
    /** @param list<Statement> $statements the probe, and the relaxed search's when one ran */
    public function __construct(
        public array $statements,
        public ?PipelineRun $run = null,
        public ?Node $reduced = null,
        public ?string $warning = null,
    ) {}

}
