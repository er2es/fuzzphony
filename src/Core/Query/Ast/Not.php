<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query\Ast;

final readonly class Not implements Node
{
    public function __construct(public Node $node) {}

    public function __toString(): string
    {
        return 'NOT ' . $this->node;
    }
}
