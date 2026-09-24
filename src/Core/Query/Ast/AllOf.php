<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query\Ast;

final readonly class AllOf implements Node
{
    /** @param list<Node> $nodes at least two */
    public function __construct(public array $nodes) {}

    public function __toString(): string
    {
        return '(' . implode(' AND ', array_map(strval(...), $this->nodes)) . ')';
    }
}
