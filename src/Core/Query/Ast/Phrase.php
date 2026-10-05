<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query\Ast;

/** @internal Words that must appear next to each other, in order: "wireless mouse". */
final readonly class Phrase implements Node
{
    /**
     * @param non-empty-list<string> $words
     * @param bool                   $synonym an alternative a synonym added to the query (SynonymExpander), not a phrase the user typed
     */
    public function __construct(public array $words, public bool $synonym = false) {}

    public function __toString(): string
    {
        return '"' . implode(' ', $this->words) . '"';
    }
}
