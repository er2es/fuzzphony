<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Engine;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Synonyms;

/**
 * An optional capability of an engine: it can stem the members of a synonym list ahead of time, so the
 * application can cache the prepared list instead of every request stemming it again. An engine that
 * does not implement it stems on the first search, as before.
 */
interface SynonymStems
{
    /** $synonyms with the stems of its members for $index's text configuration (one round trip). */
    public function stemSynonyms(IndexDefinition $index, Synonyms $synonyms): Synonyms;
}
