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

    /**
     * Called by the reindexer after a full run (not a resumed one) rebuilt every document from
     * $index. Not called when the source returned no row, unless the run was told to prune an
     * empty source (pruneEmpty). Engines that do not track which definition built the documents
     * do nothing.
     */
    public function recordReindex(IndexDefinition $index): void;

    /**
     * Starts a full rebuild next to the live index, which searches keep reading until
     * finishRebuild() swaps the rebuild in (zero-downtime reindex). Takes the index's rebuild
     * lock for the whole run and throws InvalidArgument when another run holds it. A new run
     * ($resume false) discards a leftover rebuild and starts an empty one; a resumed run
     * continues a leftover one. Returns false, with the lock released, when the run must write
     * the live index in place instead: $resume without a leftover rebuild, or an engine or a
     * role that cannot build next to the live index.
     */
    public function beginRebuild(IndexDefinition $index, bool $resume = false): bool;

    /**
     * refresh() into the rebuild beginRebuild() started.
     *
     * @param list<int|string> $ids
     *
     * @return int number of documents written
     */
    public function refreshShadow(IndexDefinition $index, array $ids): int;

    /**
     * Catches the rebuild up with the changes made to the live index meanwhile, swaps it in
     * atomically and releases the lock. When it throws, nothing was swapped and the rebuild lock
     * is still held: the caller calls finishRebuild() again or abortRebuild($index, keepShadow: true)
     * (so a resumed run can continue the rebuild).
     */
    public function finishRebuild(IndexDefinition $index): void;

    /** Releases the rebuild lock and discards the rebuild, unless $keepShadow (a failed run keeps it, so a resumed run can continue it). */
    public function abortRebuild(IndexDefinition $index, bool $keepShadow = false): void;

    /** Atomically takes up to $limit queued ids and refreshes them. Returns the number processed. */
    public function processQueue(IndexDefinition $index, int $limit): int;

    public function queueSize(IndexDefinition $index): int;

    public function inspect(IndexDefinition $index, InspectOptions $options = new InspectOptions()): InspectionReport;
}
