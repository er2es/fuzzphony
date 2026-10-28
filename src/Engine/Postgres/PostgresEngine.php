<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Engine\Capabilities;
use Fuzzphony\Core\Engine\Capability;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Engine\RecentSearchStore;
use Fuzzphony\Core\Engine\SearchLogStore;
use Fuzzphony\Core\Engine\Suggestions;
use Fuzzphony\Core\Engine\SynonymStems;
use Fuzzphony\Core\Engine\Vocabulary;
use Fuzzphony\Core\Inspection\InspectionReport;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Observability\MetricsCollector;
use Fuzzphony\Core\Observability\NullMetricsCollector;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Engine\Postgres\Inspection\PostgresInspector;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Search\FacetRunner;
use Fuzzphony\Engine\Postgres\Search\SearchRunner;

/**
 * The PostgreSQL engine: the facade of the services that do the work.
 *
 *   SearchRunner       the search: statements, relaxation, facets, rescue, hits (Search/)
 *   SearchSession      the connection of the searches and their pg_trgm settings
 *   TextConfigCache    what the process remembers about the text search configuration and the sidecar
 *   VocabularyService  the vocabulary and the did-you-mean lookup
 *   IndexMaintenance   refresh, source ids, orphans, the queue, the rebuild records
 *   SearchLogRepository / PostgresInspector / PostgresSchemaGenerator   the search log, the doctor, the DDL
 */
final class PostgresEngine implements Engine, Vocabulary, SynonymStems, Suggestions, SearchLogStore, RecentSearchStore
{
    private readonly Names $names;
    private readonly PostgresSchemaGenerator $schema;
    private readonly EngineGuard $guard;
    private readonly TextConfigCache $caches;
    private readonly VocabularyService $vocabulary;
    private readonly SearchRunner $searches;
    private readonly IndexMaintenance $maintenance;
    private readonly bool $searchLogEnabled;

    /**
     * @param string $extensionSchema schema of the pg_trgm and unaccent extensions
     * @param string $schema          schema of Fuzzphony's own tables, functions and text search configurations
     * @param bool   $searchLog       whether the schema includes the search log table (and the doctor checks it)
     * @param int    $searchLogRetentionDays the age after which the doctor suggests purging log rows
     * @param bool   $searchLogIp     whether the log is to keep the client IP: the doctor then wants the actor_ip column
     */
    public function __construct(
        private readonly Connection $connection,
        string $extensionSchema = 'public',
        string $schema = 'public',
        private readonly MetricsCollector $metrics = new NullMetricsCollector(),
        bool $searchLog = false,
        private readonly int $searchLogRetentionDays = 90,
        private readonly bool $searchLogIp = false,
    ) {
        $this->names = new Names($extensionSchema, $schema);
        $this->searchLogEnabled = $searchLog;
        $this->schema = new PostgresSchemaGenerator($this->names, $searchLog);
        $this->guard = new EngineGuard($metrics);
        $session = new SearchSession($connection, $this->guard);
        $this->caches = new TextConfigCache($connection, $this->names, $this->guard);
        $this->vocabulary = new VocabularyService($connection, $this->names, $session, $this->guard);
        $this->searches = new SearchRunner($connection, $this->names, $session, $this->caches, $this->vocabulary, new FacetRunner($session), $this->guard);
        $this->maintenance = new IndexMaintenance($connection, $this->names, $this->schema, new ShadowRebuild($connection, $this->schema), $this->guard);
    }

    /**
     * Forgets what this engine remembered for the life of the process: which words the text search configuration reduces
     * to nothing (stop words), the stems of the words of the synonyms, the expanders built from them and the synonyms'
     * digests. They hold as long as the text search configuration does not change, which a long-lived process cannot
     * know. A long-lived worker keeps them on purpose (the prepared synonym lookup and the stop-word answers would
     * otherwise be paid again on every request), so nothing calls this by itself: an application that changes the text
     * search configuration or a dictionary while its process runs calls it, or restarts the process. It also forgets which
     * vocabulary tables the did-you-mean lookup has found readable, so a table created or dropped since is looked at again.
     *
     * @internal
     */
    public function resetCaches(): void
    {
        $this->vocabulary->reset();
        $this->caches->reset();
    }

    public function name(): string
    {
        return 'postgresql';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(Capability::cases());
    }

    public function globalSchema(IndexDefinition ...$indexes): SchemaPlan
    {
        return $this->schema->global(...$indexes);
    }

    public function indexSchema(IndexDefinition $index): SchemaPlan
    {
        return $this->schema->index($index);
    }

    public function dropSchema(IndexDefinition $index): SchemaPlan
    {
        return $this->schema->drop($index);
    }

    /** @internal */
    public function schemaGenerator(): PostgresSchemaGenerator
    {
        return $this->schema;
    }

    public function search(IndexDefinition $index, SearchQuery $query): SearchResult
    {
        return $this->searches->search($index, $query);
    }

    public function explain(IndexDefinition $index, SearchQuery $query, bool $analyze = false): Explanation
    {
        return $this->searches->explain($index, $query, $analyze);
    }

    public function refresh(IndexDefinition $index, array $ids): int
    {
        return $this->maintenance->refresh($index, $ids);
    }

    public function sourceIds(IndexDefinition $index, int|string|null $after, int $limit): array
    {
        return $this->maintenance->sourceIds($index, $after, $limit);
    }

    public function pruneOrphans(IndexDefinition $index, int $batchSize = 5_000): int
    {
        return $this->maintenance->pruneOrphans($index, $batchSize);
    }

    public function recordReindex(IndexDefinition $index): void
    {
        $this->maintenance->recordReindex($index);
    }

    public function rebuildVocabulary(IndexDefinition $index): int
    {
        return $this->vocabulary->rebuild($index);
    }

    public function suggestWords(IndexDefinition $index, string $prefix, int $limit): array
    {
        return $this->vocabulary->words($index, $prefix, $limit);
    }

    public function stemSynonyms(IndexDefinition $index, Synonyms $synonyms): Synonyms
    {
        return $this->caches->stemSynonyms($index, $synonyms);
    }

    public function beginRebuild(IndexDefinition $index, bool $resume = false): bool
    {
        return $this->maintenance->beginRebuild($index, $resume);
    }

    public function refreshShadow(IndexDefinition $index, array $ids): int
    {
        return $this->maintenance->refreshShadow($index, $ids);
    }

    public function finishRebuild(IndexDefinition $index): void
    {
        $this->maintenance->finishRebuild($index);
    }

    public function abortRebuild(IndexDefinition $index, bool $keepShadow = false): void
    {
        $this->maintenance->abortRebuild($index, $keepShadow);
    }

    public function discardLeftoverRebuild(IndexDefinition $index): bool
    {
        return $this->maintenance->discardLeftoverRebuild($index);
    }

    public function processQueue(IndexDefinition $index, int $limit): int
    {
        return $this->maintenance->processQueue($index, $limit);
    }

    public function queueSize(IndexDefinition $index): int
    {
        return $this->maintenance->queueSize($index);
    }

    public function rebuildRequested(IndexDefinition $index): bool
    {
        return $this->maintenance->rebuildRequested($index);
    }

    public function recordRebuildFailure(IndexDefinition $index, string $message): void
    {
        $this->maintenance->recordRebuildFailure($index, $message);
    }

    public function writeSearchLog(array $records): int
    {
        return $this->guard->run('search log', fn(): int => $this->searchLog()->write($records), Hints::SEARCH_LOG);
    }

    public function purgeSearchLog(\DateTimeInterface $before): int
    {
        return $this->guard->run('search log', fn(): int => $this->searchLog()->purge($before), Hints::SEARCH_LOG);
    }

    public function topQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
    {
        return $this->guard->run('search log', fn(): array => $this->searchLog()->top($index->name, $from, $to, $limit, $allTenants ? null : $tenant, false), Hints::SEARCH_LOG);
    }

    public function zeroResultQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
    {
        return $this->guard->run('search log', fn(): array => $this->searchLog()->top($index->name, $from, $to, $limit, $allTenants ? null : $tenant, true), Hints::SEARCH_LOG);
    }

    public function recentSearches(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants, ?string $user, bool $anonymousOnly, ?RecordStatus $status): array
    {
        return $this->guard->run('search log', fn(): array => $this->searchLog()->recent($index->name, $from, $to, $limit, $allTenants ? null : $tenant, $user, $anonymousOnly, $status), Hints::SEARCH_LOG);
    }

    public function inspect(IndexDefinition $index, InspectOptions $options = new InspectOptions()): InspectionReport
    {
        return $this->guard->run(
            'inspection',
            fn(): InspectionReport => (new PostgresInspector($this->connection, $this->schema, $this->searchLogEnabled, $this->searchLogRetentionDays, $this->searchLogIp))->inspect($index, $options),
            'Check that this connection can read the catalog and the source.',
        );
    }

    private function searchLog(): SearchLogRepository
    {
        return new SearchLogRepository(
            $this->connection,
            $this->names,
            fn(int $dropped) => $this->metrics->increment('fuzzphony.search_log.ip_dropped', [], $dropped),
        );
    }
}
