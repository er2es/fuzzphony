<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query\Ast;

/** @internal AST node: at least one child node must match (OR). */
final readonly class AnyOf implements Node
{
    /** @param list<Node> $nodes at least two */
    public function __construct(public array $nodes) {}

    public function __toString(): string
    {
        return '(' . implode(' OR ', array_map(strval(...), $this->nodes)) . ')';
    }
}
