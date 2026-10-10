<?php

declare(strict_types=1);

namespace Fuzzphony\Core;

use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Engine\Suggestions;
use Fuzzphony\Core\Engine\SynonymStems;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Inspection\InspectionReport;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Search\FederatedSearch;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Sync\Reindexer;
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Core\Sync\ReindexResult;

/** The one service applications talk to. */
final readonly class Fuzzphony
{
    public function __construct(
        private Engine $engine,
        private IndexRegistry $registry,
    ) {}

    /** @param string|class-string $indexOrEntityClass */
    public function in(string $indexOrEntityClass): SearchBuilder
    {
        return new SearchBuilder($this->engine, $this->registry->get($indexOrEntityClass));
    }

    /** One query over several indexes with one merged list (reciprocal rank fusion); see FederatedSearch. */
    public function federated(): FederatedSearch
    {
        return new FederatedSearch($this->engine, $this->registry);
    }

    public function schema(?string $index = null): SchemaPlan
    {
        $indexes = $index === null ? array_values($this->registry->all()) : [$this->registry->get($index)];
        $plan = $this->engine->globalSchema(...$indexes);
        foreach ($indexes as $definition) {
            $plan = $plan->merge($this->engine->indexSchema($definition));
        }

        return $plan;
    }

    public function inspect(string $index, InspectOptions $options = new InspectOptions()): InspectionReport
    {
        return $this->engine->inspect($this->registry->get($index), $options);
    }

    /** @param list<int|string> $ids */
    public function refresh(string $index, array $ids): int
    {
        return $this->engine->refresh($this->registry->get($index), $ids);
    }

    /**
     * Rebuilds the whole index from the source, next to the live one, and swaps it in when it is
     * complete; the documents the source no longer returns go with the old index. See
     * ReindexOptions for writing in place, resuming and pruning.
     *
     * Call it outside a transaction: the run commits batch by batch and swaps in a short
     * transaction of its own. Inside a caller's transaction every batch and the swap's ACCESS
     * EXCLUSIVE lock would join it (searches blocked until the caller commits), so the engine
     * writes the live index in place there, as before 0.5 (ReindexResult::$swapped is false).
     */
    public function reindex(string $index, ReindexOptions $options = new ReindexOptions()): ReindexResult
    {
        return (new Reindexer($this->engine))->run($this->registry->get($index), $options);
    }

    /**
     * Rebuilds only the vocabulary of a fuzzy index ("did you mean": its words and in how many documents
     * each occurs) from the documents already indexed; a full reindex does it after the documents.
     *
     * @return int the number of words
     *
     * @throws InvalidArgument when the engine keeps no vocabulary or the index has no fuzzy field
     */
    public function rebuildVocabulary(string $index): int
    {
        return (new Reindexer($this->engine))->vocabulary($this->registry->get($index));
    }

    /**
     * Search-as-you-type: the search text with the word being typed completed from the index's
     * vocabulary ("wireless hea" -> "wireless headphones", "wireless headset"), the most frequent
     * word first. Only the last word is completed, and only when the text ends with it (not with a
     * space or a symbol). The completions are in the index's normalised form (lowercase, accents
     * folded) and plain text, not HTML: escape them when you render them.
     *
     * The vocabulary is filled by a full reindex (and `fuzzphony:reindex --vocabulary`), so a word that
     * is new since the last rebuild is not suggested yet. A tenant-scoped index, an index without a
     * typo-tolerant field, and an engine without a vocabulary give an empty list.
     *
     * @param int $limit 1 to 20
     *
     * @return list<string>
     *
     * @throws InvalidArgument for a limit outside 1 to 20
     */
    public function suggest(string $index, string $text, int $limit = 8): array
    {
        if ($limit < 1 || $limit > 20) {
            throw new InvalidArgument(sprintf('The suggestion limit must be between 1 and 20, got %d.', $limit));
        }
        $definition = $this->registry->get($index);
        $text = mb_substr(ltrim($text), 0, 100);
        if (!$this->engine instanceof Suggestions || preg_match('/^(.*?)([\p{L}\p{N}]+)$/su', $text, $m) !== 1) {
            return [];
        }

        return array_map(static fn(string $word): string => $m[1] . $word, $this->engine->suggestWords($definition, $m[2], $limit));
    }

    /**
     * Replaces the synonyms of an index at run time (for example from a database table the application
     * keeps); the next search uses them. No schema change, no reindex. The application loads them
     * itself, and caches them, once per request or process.
     */
    public function useSynonyms(string $index, \Fuzzphony\Core\Definition\Synonyms $synonyms): void
    {
        $this->registry->register($this->registry->get($index)->withSynonyms($synonyms));
    }

    /**
     * The same synonyms with their members stemmed for the index's language (one database round trip),
     * for an application that caches them: stem once when the list changes, keep the result (it
     * serializes) in a cache, and hand it to useSynonyms() on every request. Without it the first search
     * of each request stems every member, which costs about 50 microseconds per entry.
     *
     * @throws InvalidArgument when the engine cannot prepare synonyms
     */
    public function stemSynonyms(string $index, Synonyms $synonyms): Synonyms
    {
        return $this->engine instanceof SynonymStems
            ? $this->engine->stemSynonyms($this->registry->get($index), $synonyms)
            : throw new InvalidArgument(sprintf('The "%s" engine does not prepare synonyms; useSynonyms() works without it.', $this->engine->name()));
    }

    public function registry(): IndexRegistry
    {
        return $this->registry;
    }

    public function engine(): Engine
    {
        return $this->engine;
    }
}
