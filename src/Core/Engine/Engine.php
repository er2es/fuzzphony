<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Engine;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Inspection\InspectionReport;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\SearchResult;

/**
 * A database-specific implementation. Core never talks SQL directly; everything
 * dialect-specific lives behind this interface (see tests/Conformance).
 */
interface Engine
{
    public function name(): string;

    public function capabilities(): Capabilities;

    /** Objects shared by all indexes (extensions, text configurations, helper functions). */
    public function globalSchema(IndexDefinition ...$indexes): SchemaPlan;

    /** Sidecar table, refresh function, sync triggers and indexes of one index. */
    public function indexSchema(IndexDefinition $index): SchemaPlan;

    /** Everything needed to remove the index (the source data is never touched). */
    public function dropSchema(IndexDefinition $index): SchemaPlan;

    public function search(IndexDefinition $index, SearchQuery $query): SearchResult;

    public function explain(IndexDefinition $index, SearchQuery $query, bool $analyze = false): Explanation;

    /**
     * (Re)builds the documents with these ids; ids that no longer exist in the source are removed.
     *
     * @param list<int|string> $ids
     *
     * @return int number of documents written
     */
    public function refresh(IndexDefinition $index, array $ids): int;

    /**
     * Source ids after $after in ascending order: keyset pagination for reindexing.
     *
     * @return list<int|string>
     */
    public function sourceIds(IndexDefinition $index, int|string|null $after, int $limit): array;

    /**
     * Removes indexed documents whose id the source no longer returns ("orphans", e.g. left by a
     * TRUNCATE before the TRUNCATE sync trigger existed, or by changes made while sync was off).
     * Works through the index in batches of $batchSize, so no single statement holds its locks long.
     *
     * @return int number of documents removed
     */
    public function pruneOrphans(IndexDefinition $index, int $batchSize = 5_000): int;

    /** Atomically takes up to $limit queued ids and refreshes them. Returns the number processed. */
    public function processQueue(IndexDefinition $index, int $limit): int;

    public function queueSize(IndexDefinition $index): int;

    public function inspect(IndexDefinition $index, InspectOptions $options = new InspectOptions()): InspectionReport;
}
