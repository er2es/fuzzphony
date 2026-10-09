<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Engine;

use Fuzzphony\Core\Definition\IndexDefinition;

/**
 * An optional capability of an engine: it keeps the vocabulary of a fuzzy index (its words and in
 * how many documents each occurs), which "did you mean" reads. An engine that does not implement it
 * simply gives no suggestions, and the reindexer skips it.
 */
interface Vocabulary
{
    /**
     * Rebuilds the vocabulary of $index from its indexed documents, replacing the old one in one
     * transaction. A full reindex calls it after it has finished; it is never kept up to date per write.
     *
     * @return int the number of words
     */
    public function rebuildVocabulary(IndexDefinition $index): int;
}
