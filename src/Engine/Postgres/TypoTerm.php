<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

/**
 * @internal A typo-tolerant leaf of the query as the highlighter needs it: the normalised words the search matched
 * fuzzily (FuzzyQueryCompiler's needle), how many words they are, whether the leaf is a prefix, the field it is
 * scoped to (null: every field), and the swapped-letter variants of the word the search also matched as whole words
 * (Transpositions; `mosue` finds `mouse`, which trigram similarity alone does not reach).
 */
final readonly class TypoTerm
{
    public int $words;

    /** @param list<string> $variants */
    public function __construct(
        public string $needle,
        public bool $prefix = false,
        public ?string $field = null,
        public array $variants = [],
    ) {
        $this->words = substr_count($needle, ' ') + 1;
    }
}
