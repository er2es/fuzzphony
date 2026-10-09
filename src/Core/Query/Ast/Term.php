<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query\Ast;

/** @internal AST node: a single word, optionally a prefix ("keyb*"). */
final readonly class Term implements Node
{
    public function __construct(
        public string $text,
        /** "keyb*" matches keyboard, keyboards, ... */
        public bool $prefix = false,
        /** An alternative a synonym added to the query (SynonymExpander), not a word the user typed. */
        public bool $synonym = false,
    ) {}

    public function __toString(): string
    {
        return $this->text . ($this->prefix ? '*' : '');
    }
}
