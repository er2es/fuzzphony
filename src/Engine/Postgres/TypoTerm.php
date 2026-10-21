<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

/**
 * @internal A typo-tolerant leaf of the query as the highlighter needs it: the normalised words the search matched
 * fuzzily (FuzzyQueryCompiler's needle), how many words they are, whether the leaf is a prefix, and the field it is
 * scoped to (null: every field).
 */
final readonly class TypoTerm
{
    public int $words;

    public function __construct(
        public string $needle,
        public bool $prefix = false,
        public ?string $field = null,
    ) {
        $this->words = substr_count($needle, ' ') + 1;
    }
}
