<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query;

use Fuzzphony\Core\Query\Ast\Node;

/** @internal The result of parsing end-user search syntax: an AST plus warnings for corrected input. */
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
