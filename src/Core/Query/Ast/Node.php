<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query\Ast;

/** Engine-agnostic search expression. Engines compile it into their native query language. */
interface Node
{
    /** Human-readable, canonical form (used by explain output and tests). */
    public function __toString(): string;
}
