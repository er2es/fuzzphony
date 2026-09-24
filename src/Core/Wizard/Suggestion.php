<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Wizard;

use Fuzzphony\Core\Definition\IndexDefinition;

final readonly class Suggestion
{
    /**
     * @param list<Decision> $decisions every column, including the ones skipped (role "skip")
     * @param list<string>   $notes
     */
    public function __construct(
        public ?IndexDefinition $definition,
        public array $decisions,
        public array $notes = [],
    ) {}
}
