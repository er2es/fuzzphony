<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query;

use Fuzzphony\Core\Query\Ast\Node;

final readonly class ParsedQuery
{
    /** @param list<string> $warnings */
    public function __construct(
        public ?Node $root,
        public array $warnings = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->root === null;
    }
}
