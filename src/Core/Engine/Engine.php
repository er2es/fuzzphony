<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Engine;

use Fuzzphony\Core\Analytics\LoggedSearch;
use Fuzzphony\Core\Analytics\QueryStat;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Inspection\InspectionReport;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Core\Wizard\SourceIntrospector;

/**
 * A database-specific implementation. Core never talks SQL directly; everything
 * dialect-specific lives behind this interface (see tests/Conformance).
 *
 * @internal
 */
interface Engine
{
    public function name(): string;

    /**
     * Forgets what the engine remembered for the life of the process (which words a text search configuration reduces to nothing,
     * the stems of the synonyms, which vocabulary tables are readable). A long-lived process calls it after a text search
     * configuration or a dictionary changed, or is restarted.
     */
    public function resetCaches(): void;

    /** The reader of table structures behind the configuration wizard. */
    public function introspector(): SourceIntrospector;

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
     * It ends a full in-place run, so it also completes the rebuild requests queued before that run
     * started (see rebuildRequested()).
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
     * lock for the whole run and throws RebuildAlreadyRunning when another run holds it. A new run
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

    /**
     * Discards what a failed rebuild left behind, before a full in-place reindex changes the live
     * index (a resume could not complete that rebuild consistently afterwards). One transaction,
     * never a session lock (safe behind a transaction-pooling proxy): true when it discarded
     * something; false, changing nothing, when a rebuild is running or nothing is left over.
     * Either way it marks the start of that full in-place run (see rebuildRequested()). Engines
     * without rebuilds return false.
     */
    public function discardLeftoverRebuild(IndexDefinition $index): bool;

    /** Atomically takes up to $limit queued ids and refreshes them. Returns the number processed. */
    public function processQueue(IndexDefinition $index, int $limit): int;

    public function queueSize(IndexDefinition $index): int;

    /**
     * Whether a full rebuild of the index was requested ("queue" mode: a TRUNCATE that needs a
     * full resync queues one such job instead of every document id). The worker runs it before
     * the queued ids. A full run that succeeds completes the requests queued before it started:
     * finishRebuild() of a run begun without $resume, or pruneOrphans() at the end of a full
     * in-place run (one begun with beginRebuild() or discardLeftoverRebuild()). A run that fails,
     * or is killed, keeps them.
     */
    public function rebuildRequested(IndexDefinition $index): bool;

    /**
     * The sources of a multi-source index for which a rebuild was requested (a TRUNCATE of a table one source reads queues a
     * rebuild of that source only); empty for a single-source index and when none is requested.
     *
     * @return list<string> source names
     */
    public function rebuildRequestedSources(IndexDefinition $index): array;

    /**
     * Records that the full rebuild the worker ran for a request failed (when, how often in a row,
     * the message), for the doctor. The next full run that succeeds clears it. Engines without
     * such requests do nothing.
     */
    public function recordRebuildFailure(IndexDefinition $index, string $message): void;

    public function inspect(IndexDefinition $index, InspectOptions $options = new InspectOptions()): InspectionReport;

    /**
     * Rebuilds the vocabulary of $index (the words of a fuzzy index and in how many documents each occurs, which "did you mean"
     * and complete() read) from its indexed documents, replacing the old one in one transaction. A full reindex calls it after
     * it has finished; it is never kept up to date per write.
     *
     * @return int the number of words
     */
    public function rebuildVocabulary(IndexDefinition $index): int;

    /**
     * The words of the vocabulary that start with $prefix, the most frequent first. The words are in the index's normalised form
     * (lowercase, accents folded). A tenant-scoped index, or one without a typo-tolerant field, has no vocabulary: the list is empty.
     *
     * @return list<string>
     */
    public function suggestWords(IndexDefinition $index, string $prefix, int $limit): array;

    /** $synonyms with the stems of its members for $index's text configuration (one round trip). */
    public function stemSynonyms(IndexDefinition $index, Synonyms $synonyms): Synonyms;

    /**
     * @param list<SearchRecord> $records
     *
     * @return int the number of records written
     */
    public function writeSearchLog(array $records): int;

    /** @return int the number of records deleted */
    public function purgeSearchLog(\DateTimeInterface $before): int;

    /**
     * The most searched texts of $index from $from until $to (null = now), most searched first. A tenant-scoped index is reported
     * for $tenant, or across tenants when $allTenants.
     *
     * @return list<QueryStat>
     */
    public function topQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array;

    /**
     * The texts that found nothing, with the same arguments as topQueries().
     *
     * @return list<QueryStat>
     */
    public function zeroResultQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array;

    /**
     * The most recent logged searches of $index from $from until $to (null = now), newest first, whatever their status.
     * The tenant arguments work as in topQueries(). $user keeps the searches of that user, $anonymousOnly those without a user
     * (not both), $status those that ended so.
     *
     * @return list<LoggedSearch>
     */
    public function recentSearches(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants, ?string $user, bool $anonymousOnly, ?RecordStatus $status): array;
}
