<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Engine;

use Fuzzphony\Core\Definition\IndexDefinition;

/**
 * An optional capability of an engine: it completes the beginning of a word from the index's
 * vocabulary (search-as-you-type). An engine that does not implement it gives no suggestions.
 */
interface Suggestions
{
    /**
     * The words of the vocabulary that start with $prefix, the most frequent first. The words are in
     * the index's normalised form (lowercase, accents folded). A tenant-scoped index, or one without a
     * typo-tolerant field, has no vocabulary to suggest from: the list is empty.
     *
     * @return list<string>
     */
    public function suggestWords(IndexDefinition $index, string $prefix, int $limit): array;
}
