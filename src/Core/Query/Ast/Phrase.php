<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query\Ast;

/** Words that must appear next to each other, in order: "wireless mouse". */
final readonly class Phrase implements Node
{
    /** @param non-empty-list<string> $words */
    public function __construct(public array $words) {}

    public function __toString(): string
    {
        return '"' . implode(' ', $this->words) . '"';
    }
}
