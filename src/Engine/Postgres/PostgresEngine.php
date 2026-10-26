<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Database\TransactionAware;
use Fuzzphony\Core\Definition\FilterType;
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
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Inspection\InspectionReport;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Observability\MetricsCollector;
use Fuzzphony\Core\Observability\NullMetricsCollector;
use Fuzzphony\Core\Query\Ast\AllOf;
use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\FieldScoped;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\NodeInspector;
use Fuzzphony\Core\Query\Ast\Not;
use Fuzzphony\Core\Query\Ast\Phrase;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\Filter\Condition;
use Fuzzphony\Core\Query\Filter\Operator;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Query\QueryRenderer;
use Fuzzphony\Core\Query\Relaxation;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Query\SynonymExpander;
use Fuzzphony\Core\Ranking\FuzzyMode;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\FacetValue;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\ScoreBreakdown;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Inspection\PostgresInspector;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Schema\Types;
use Fuzzphony\Engine\Postgres\Sql\DocumentSql;
use Fuzzphony\Engine\Postgres\Sql\FilterCompiler;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Engine\Postgres\Sql\SearchSqlBuilder;
use Fuzzphony\Engine\Postgres\Sql\Sql;
use Fuzzphony\Engine\Postgres\Sql\Transpositions;
use Fuzzphony\Engine\Postgres\Sql\TsQueryCompiler;

final class PostgresEngine implements Engine, Vocabulary, SynonymStems, Suggestions, SearchLogStore, RecentSearchStore
{
    private const PROBE_LABEL = 'relaxation probe';
    private const SUGGEST_LABEL = 'did you mean';
    private const string EXACT_LABEL = 'exact total';
    private const string WORD_SIMILARITY = 'pg_trgm.word_similarity_threshold';
    private const string SEARCH_HINT = 'Run "bin/console fuzzphony:doctor" to check the index.';
    private const string FACET_LABEL = 'facet: ';
    /**
     * The trigram similarity a vocabulary word needs to be a candidate of the did-you-mean lookup (and the rescue). A letter
     * replaced in the middle of a 4-letter word leaves 2 of 8 trigrams in common, exactly 0.25 (0.3 missed 40% of the
     * one-edit typos of 4-letter words); a swap in a 6-letter word leaves 0.27. Lower finds nothing more and reads more.
     */
    private const float SUGGEST_SIMILARITY = 0.25;
    /** The label of the statements of the zero-result rescue starts with this (see rescued()). */
    private const string RESCUE_PREFIX = 'rescue: ';
    private const string RESTRICTED_PLAN = 'No plan for a search restricted to some fields: its estimates and row counts are taken over every field.';
    private const string REBUILD_HINT = 'Run "fuzzphony:schema --apply" and "fuzzphony:doctor".';
    private const string SEARCH_LOG_HINT = 'Run "fuzzphony:schema --apply" (the log must be enabled) and "fuzzphony:doctor"; the role needs INSERT, SELECT and DELETE on the search log table.';

    private readonly Names $names;
    private readonly PostgresSchemaGenerator $schema;
    private readonly ShadowRebuild $rebuild;
    private readonly bool $searchLogEnabled;
    /** Most stems kept per text configuration; the cache starts over beyond it. */
    private const int STEM_CACHE = 5_000;
    /** @var array<string, SynonymExpander> an expander per index definition, built once */
    private array $expanders = [];
    /** @var array<string, array<string, string>> text configuration => lowercase word => stem */
    private array $stems = [];
    /** Most leaf tsqueries remembered per text configuration; the cache starts over beyond it. */
    private const int EMPTY_CACHE = 5_000;
    /** @var array<string, array<string, bool>> text configuration => leaf tsquery => whether it reduces to nothing (a stop word) */
    private array $emptyAnswers = [];
    /** @var array<string, bool> sidecar table => whether it has the tsv_capped column (see cappedColumn()) */
    private array $cappedColumns = [];
    /** @var \WeakMap<Synonyms, string>|null the content digest of each Synonyms object (immutable), made once; see expandSynonyms() */
    private ?\WeakMap $synonymDigests = null;
    /** @var array<string, true> vocabulary tables this engine has seen exist and be readable; only a positive answer is kept (see suggest()) */
    private array $vocabularyReadable = [];
    /**
     * Set while this engine itself holds the transaction of a search (see inSearchTransaction()): the pg_trgm settings it has set
     * in it, by name, with the value the session had before (original) and the one in force now (current). Null outside one.
     *
     * @var array<string, array{original: string|null, current: string|null}>|null
     */
    private ?array $settings = null;

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
        $this->rebuild = new ShadowRebuild($connection, $this->schema);
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
        $this->vocabularyReadable = [];
        $this->expanders = [];
        $this->stems = [];
        $this->emptyAnswers = [];
        $this->cappedColumns = [];
        $this->synonymDigests = null;
    }

    /**
     * Whether the sidecar has the optional tsv_capped column (it comes with fuzzphony:schema --apply; PostgresSchemaGenerator::CAPPED_COLUMN):
     * asked only by a statement that needs it (a phrase or a field-scoped word on an index with several fields), once per table, the answer
     * kept until resetCaches(). A role that may not read the column counts as "no column". Without it those statements read the later fields'
     * vectors for every document tsv does not match: right, and slower.
     *
     * @return \Closure(): bool
     */
    private function cappedColumn(IndexDefinition $index): \Closure
    {
        return function () use ($index): bool {
            $table = $this->names->sidecar($index);

            return $this->cappedColumns[$table] ??= $this->connection->fetchValue(
                "SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass(:table) AND attname = :column AND NOT attisdropped AND has_column_privilege(attrelid, attname, 'SELECT'))",
                ['table' => $table, 'column' => PostgresSchemaGenerator::CAPPED_COLUMN],
            ) === true;
        };
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
        return $this->execute($index, $query)['result'];
    }

    public function explain(IndexDefinition $index, SearchQuery $query, bool $analyze = false): Explanation
    {
        $run = $this->execute($index, $query);
        $plan = [];
        // the plan of the last search statement (the relaxation probe and the suggestion lookup are listed but are not the search)
        $last = null;
        foreach ($run['statements'] as $statement) {
            $label = str_starts_with($statement['label'], self::RESCUE_PREFIX) ? substr($statement['label'], strlen(self::RESCUE_PREFIX)) : $statement['label'];
            if (!in_array($label, [self::PROBE_LABEL, self::SUGGEST_LABEL, self::EXACT_LABEL], true) && !str_starts_with($label, self::FACET_LABEL)) {
                $last = $statement;
            }
        }
        if ($last !== null && $query->allowedFields !== null) {
            // The planner's row estimates (the statistics of tsv and fz) and EXPLAIN ANALYZE's row counts ("Rows Removed by
            // Filter" after GIN(tsv)) count the words of every field: they would tell a restricted viewer how many
            // documents have a word in a field it may not see. The statements are shown; the plan is not.
            $plan = [self::RESTRICTED_PLAN];
        } elseif ($last !== null) {
            $restore = !($this->connection instanceof TransactionAware) || $this->connection->inTransaction();
            /** @var \Closure(): list<string> $explain */
            $explain = fn() => $this->connection->transactional(fn(Connection $c) => self::withSimilarityThreshold(
                $c,
                $run['threshold'],
                $restore,
                static function () use ($c, $last, $analyze) {
                    $rows = $c->fetchAll(($analyze ? 'EXPLAIN (ANALYZE, BUFFERS) ' : 'EXPLAIN ') . $last['sql'], $last['params']);

                    return array_map(static fn(array $row): string => Coerce::str(reset($row)), $rows);
                },
            ));
            $plan = $this->guard('explain', $explain, 'Run "bin/console fuzzphony:doctor" to check the index.');
        }

        return new Explanation($run['result']->interpretedAs ?? '', $run['statements'], $plan, $run['result']);
    }

    public function refresh(IndexDefinition $index, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->guard('refresh', fn(): int => Coerce::int($this->connection->fetchValue(
            sprintf('SELECT %s(CAST(:ids AS %s[]))', $this->names->refreshFunction($index), Types::id($index->idType)),
            ['ids' => Sql::arrayLiteral(array_values($ids))],
        )), 'Run "fuzzphony:schema --apply" to create the refresh function, then "fuzzphony:doctor".');
    }

    public function sourceIds(IndexDefinition $index, int|string|null $after, int $limit): array
    {
        $params = ['limit' => $limit];
        $where = '';
        if ($after !== null) {
            $where = sprintf('WHERE doc.fz_id > CAST(:after AS %s)', Types::id($index->idType));
            $params['after'] = (string) $after;
        }
        $rows = $this->guard('source ids', fn(): array => $this->connection->fetchAll(sprintf(
            'SELECT doc.fz_id::text AS id FROM (%s) AS doc %s ORDER BY doc.fz_id LIMIT :limit',
            DocumentSql::select($index),
            $where,
        ), $params), 'Run "bin/console fuzzphony:doctor": it checks that the source can be queried.');

        return array_map(static fn(array $row): int|string => $index->idType->cast(Coerce::str($row['id'])), $rows);
    }

    public function pruneOrphans(IndexDefinition $index, int $batchSize = 5_000): int
    {
        if ($batchSize < 1) {
            throw new InvalidArgument('Batch size must be >= 1.');
        }
        // Keyset pagination over the sidecar: each statement checks (and locks) at most one batch,
        // and the whole run reads every indexed id once. Same anti-join as the refresh function.
        $sidecar = $this->names->sidecar($index);
        $sql = static fn(bool $first): string => sprintf(
            <<<'SQL'
                WITH batch AS (
                    SELECT s.id FROM %1$s AS s %2$s ORDER BY s.id LIMIT :limit
                ), removed AS (
                    DELETE FROM %1$s AS s
                    USING batch AS b
                    WHERE s.id = b.id
                      AND NOT EXISTS (SELECT 1 FROM (%3$s) AS doc WHERE doc.fz_id = b.id)
                    RETURNING 1
                )
                SELECT (SELECT count(*) FROM batch) AS scanned,
                       (SELECT b.id::text FROM batch AS b ORDER BY b.id DESC LIMIT 1) AS last,
                       (SELECT count(*) FROM removed) AS removed
                SQL,
            $sidecar,
            $first ? '' : sprintf('WHERE s.id > CAST(:after AS %s)', Types::id($index->idType)),
            DocumentSql::select($index),
        );

        return $this->guard('orphan pruning', function () use ($index, $sql, $batchSize): int {
            $removed = 0;
            $after = null;
            do {
                $params = ['limit' => $batchSize];
                if ($after !== null) {
                    $params['after'] = $after;
                }
                $row = $this->connection->fetchAll($sql($after === null), $params)[0];
                $removed += Coerce::int($row['removed']);
                $after = $row['last'] === null ? null : Coerce::str($row['last']);
            } while ($after !== null && Coerce::int($row['scanned']) === $batchSize);
            // the end of a full in-place run
            $this->rebuild->complete($index);

            return $removed;
        }, 'Run "fuzzphony:schema --apply" and check "fuzzphony:doctor".');
    }

    public function recordReindex(IndexDefinition $index): void
    {
        // a missing table is a no-op (the statement checks), so a failure is almost always a missing privilege
        $this->guard(
            'reindex record',
            fn(): int => $this->connection->execute($this->schema->reindexed($index)),
            sprintf('The role running the reindex needs SELECT and UPDATE on %s.', $this->names->meta()),
        );
    }

    /**
     * The words of the index's typo-tolerant text and in how many documents each occurs, built from
     * the live index table. One transaction deletes the old words and inserts the new ones, so readers
     * (only "did you mean" reads it) keep the old words until it commits and never wait; it takes no
     * ACCESS EXCLUSIVE lock, needs no TRUNCATE or TEMPORARY privilege (the role needs SELECT, INSERT and
     * DELETE on the table) and may join a caller's transaction. An advisory lock keeps two rebuilds of
     * the same index from running at once.
     */
    public function rebuildVocabulary(IndexDefinition $index): int
    {
        $vocabulary = $this->names->vocabulary($index);

        return $this->guard('vocabulary', function () use ($index, $vocabulary): int {
            $granted = $this->connection->fetchValue(
                "SELECT to_regclass(:a) IS NOT NULL AND has_table_privilege(to_regclass(:b), 'INSERT') AND has_table_privilege(to_regclass(:c), 'DELETE')",
                ['a' => $vocabulary, 'b' => $vocabulary, 'c' => $vocabulary],
            );
            if ($granted !== true) {
                throw new \RuntimeException(sprintf('%s does not exist or this role may not write it.', $vocabulary));
            }

            return $this->connection->transactional(function (Connection $c) use ($index, $vocabulary): int {
                $c->fetchValue('SELECT pg_advisory_xact_lock(hashtext(:key))', ['key' => $vocabulary]);
                $c->execute(sprintf('DELETE FROM %s', $vocabulary));
                $c->execute(sprintf(
                    "INSERT INTO %s (word, freq) SELECT w, count(*)::integer FROM (SELECT DISTINCT s.id, w FROM %s AS s CROSS JOIN LATERAL unnest(string_to_array(s.fz, ' ')) AS w WHERE char_length(w) >= :min) AS t GROUP BY w",
                    $vocabulary,
                    $this->names->sidecar($index),
                ), ['min' => $index->thresholds->fuzzyMinLength]);

                return Coerce::int($c->fetchValue(sprintf('SELECT count(*) FROM %s', $vocabulary)));
            });
        }, sprintf('Run "bin/console fuzzphony:schema --apply" to create %1$s, and give the reindexing role SELECT, INSERT and DELETE on it (GRANT ... ON ALL TABLES IN SCHEMA covers it once the table exists).', $vocabulary));
    }

    public function suggestWords(IndexDefinition $index, string $prefix, int $limit): array
    {
        if ($index->tenant !== null || !$index->hasFuzzy()) {
            return []; // the vocabulary has no tenant column: it would leak the words of other tenants
        }
        $vocabulary = $this->names->vocabulary($index);

        return $this->guard('suggest', function () use ($vocabulary, $prefix, $limit): array {
            // a vocabulary the schema has not created yet, or one this role may not read, suggests nothing instead of failing
            if ($this->connection->fetchValue("SELECT to_regclass(:a) IS NOT NULL AND has_table_privilege(to_regclass(:b), 'SELECT')", ['a' => $vocabulary, 'b' => $vocabulary]) !== true) {
                return [];
            }
            // normalised like the words are, then bound as a constant pattern, so the planner can use the prefix index
            $norm = Coerce::str($this->connection->fetchValue(sprintf('SELECT %s(:p)', $this->names->normFunction()), ['p' => $prefix]));
            if ($norm === '') {
                return [];
            }
            $rows = $this->connection->fetchAll(
                sprintf("SELECT word FROM %s WHERE word LIKE :pattern ESCAPE '!' ORDER BY freq DESC, word LIMIT %d", $vocabulary, $limit),
                ['pattern' => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $norm) . '%'],
            );

            return array_values(array_map(static fn(array $row): string => Coerce::str($row['word']), $rows));
        }, 'Run "bin/console fuzzphony:doctor" to check the index.');
    }

    public function beginRebuild(IndexDefinition $index, bool $resume = false): bool
    {
        return $this->guard('rebuild', fn(): bool => $this->rebuild->begin($index, $resume), self::REBUILD_HINT);
    }

    public function refreshShadow(IndexDefinition $index, array $ids): int
    {
        return $this->guard('rebuild', fn(): int => $this->rebuild->refresh($index, $ids), self::REBUILD_HINT);
    }

    public function finishRebuild(IndexDefinition $index): void
    {
        $this->guard('rebuild', function () use ($index): null {
            $this->rebuild->finish($index);

            return null;
        }, self::REBUILD_HINT);
    }

    public function abortRebuild(IndexDefinition $index, bool $keepShadow = false): void
    {
        $this->guard('rebuild', function () use ($index, $keepShadow): null {
            $this->rebuild->abort($index, $keepShadow);

            return null;
        }, self::REBUILD_HINT);
    }

    public function discardLeftoverRebuild(IndexDefinition $index): bool
    {
        return $this->guard('rebuild', fn(): bool => $this->rebuild->discardLeftover($index), self::REBUILD_HINT);
    }

    public function processQueue(IndexDefinition $index, int $limit): int
    {
        // Taking the batch and refreshing it happen in ONE statement and transaction:
        // if the refresh fails, the ids stay in the queue. SKIP LOCKED lets workers run in parallel.
        $sql = sprintf(
            <<<'SQL'
                WITH batch AS (
                    DELETE FROM %1$s
                    WHERE (index_name, doc_id) IN (
                        SELECT index_name, doc_id FROM %1$s
                        WHERE index_name = :index AND doc_id <> '*'
                        ORDER BY queued_at
                        LIMIT :limit
                        FOR UPDATE SKIP LOCKED
                    )
                    RETURNING doc_id
                ), refreshed AS (
                    SELECT %2$s(ARRAY(SELECT doc_id::%3$s FROM batch)) AS written
                )
                SELECT (SELECT count(*) FROM batch) FROM refreshed
                SQL,
            $this->names->queue(),
            $this->names->refreshFunction($index),
            Types::id($index->idType),
        );

        return $this->guard(
            'queue processing',
            fn(): int => Coerce::int($this->connection->transactional(
                static fn(Connection $c): mixed => $c->fetchValue($sql, ['index' => $index->name, 'limit' => $limit]),
            )),
            'Run "fuzzphony:schema --apply" and check "fuzzphony:doctor".',
        );
    }

    public function queueSize(IndexDefinition $index): int
    {
        return $this->guard('queue size', fn(): int => Coerce::int($this->connection->fetchValue(
            sprintf('SELECT count(*) FROM %s WHERE index_name = :index', $this->names->queue()),
            ['index' => $index->name],
        )), 'Run "fuzzphony:schema --apply" to create the queue table.');
    }

    public function rebuildRequested(IndexDefinition $index): bool
    {
        return $this->guard('queue processing', fn(): bool => (bool) $this->connection->fetchValue(
            sprintf("SELECT EXISTS (SELECT 1 FROM %s WHERE index_name = :index AND doc_id = '*')", $this->names->queue()),
            ['index' => $index->name],
        ), 'Run "fuzzphony:schema --apply" and check "fuzzphony:doctor".');
    }

    public function recordRebuildFailure(IndexDefinition $index, string $message): void
    {
        $this->guard('rebuild failure record', fn(): int => $this->connection->execute(
            sprintf('UPDATE %s SET rebuild_failed_at = now(), rebuild_failures = coalesce(rebuild_failures, 0) + 1, rebuild_error = :message WHERE index_name = :index', $this->names->meta()),
            ['index' => $index->name, 'message' => $message],
        ), sprintf('Run "fuzzphony:schema --apply" (it adds the failure columns); the worker role needs SELECT and UPDATE on %s.', $this->names->meta()));
    }

    public function writeSearchLog(array $records): int
    {
        return $this->guard('search log', fn(): int => $this->searchLog()->write($records), self::SEARCH_LOG_HINT);
    }

    public function purgeSearchLog(\DateTimeInterface $before): int
    {
        return $this->guard('search log', fn(): int => $this->searchLog()->purge($before), self::SEARCH_LOG_HINT);
    }

    public function topQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
    {
        return $this->guard('search log', fn(): array => $this->searchLog()->top($index->name, $from, $to, $limit, $allTenants ? null : $tenant, false), self::SEARCH_LOG_HINT);
    }

    public function zeroResultQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
    {
        return $this->guard('search log', fn(): array => $this->searchLog()->top($index->name, $from, $to, $limit, $allTenants ? null : $tenant, true), self::SEARCH_LOG_HINT);
    }

    public function recentSearches(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants, ?string $user, bool $anonymousOnly, ?RecordStatus $status): array
    {
        return $this->guard('search log', fn(): array => $this->searchLog()->recent($index->name, $from, $to, $limit, $allTenants ? null : $tenant, $user, $anonymousOnly, $status), self::SEARCH_LOG_HINT);
    }

    private function searchLog(): SearchLogRepository
    {
        return new SearchLogRepository(
            $this->connection,
            $this->names,
            fn(int $dropped) => $this->metrics->increment('fuzzphony.search_log.ip_dropped', [], $dropped),
        );
    }

    public function inspect(IndexDefinition $index, InspectOptions $options = new InspectOptions()): InspectionReport
    {
        return $this->guard(
            'inspection',
            fn(): InspectionReport => (new PostgresInspector($this->connection, $this->schema, $this->searchLogEnabled, $this->searchLogRetentionDays, $this->searchLogIp))->inspect($index, $options),
            'Check that this connection can read the catalog and the source.',
        );
    }

    /**
     * One search, with the zero-result rescue: a search that found nothing, and has a word with a unique one-edit spelling
     * in the vocabulary, is run once more with the corrected words (never again: the second run cannot rescue) and its
     * result, flagged, is the answer when it found something without the relaxation dropping a word. See executeOnce() for when a search is rescued.
     *
     * @return array{
     *     result: SearchResult,
     *     statements: list<array{label: string, sql: string, params: array<string, scalar|null>}>,
     *     threshold: float|null
     * }
     */
    private function execute(IndexDefinition $index, SearchQuery $query): array
    {
        $started = hrtime(true);
        $run = $this->executeOnce($index, $query, true);
        $rescue = $run['rescue'];
        if ($rescue !== null) {
            $second = $this->executeOnce($index, $query->withText($rescue['to']), false);
            // The corrected search must find the whole corrected query: when it only found something after the relaxation dropped
            // a word ("mp use" -> "mp usb" -> "usb"), the rescue would compound two guesses and say "showing results for
            // 'mp usb'" about hits that are for "usb" alone, so the original empty answer stands.
            if ($second['result']->total > 0 && !$second['relaxed']) {
                $run = $this->rescued($query, $run, $second, $rescue, $started);
                $this->metrics->increment('fuzzphony.search.rescued', ['index' => $index->name, 'query' => $query->text]);
            } else {
                // nothing either: the answer is the original, empty one; explain() still lists what the attempt ran
                $run['statements'] = [...$run['statements'], ...self::rescueLabels($second['statements'])];
                $run['threshold'] = $second['threshold'];
            }
        }
        $result = $run['result'];
        $this->metrics->observe('fuzzphony.search.took_ms', $result->tookMs, ['index' => $index->name, 'query' => $query->text]);
        if (array_any($run['statements'], static fn(array $s): bool => str_ends_with($s['label'], 'fallback: full-text + fuzzy'))) {
            $this->metrics->increment('fuzzphony.search.fallback', ['index' => $index->name, 'query' => $query->text]);
        }

        return ['result' => $result, 'statements' => $run['statements'], 'threshold' => $run['threshold']];
    }

    /**
     * The answer of a rescued search: the corrected search's hits, total and facets, flagged with what the visitor typed, with
     * the statements of both (the second's labelled "rescue: ...") and a warning that says what happened.
     *
     * @param array{result: SearchResult, statements: list<array{label: string, sql: string, params: array<string, scalar|null>}>, threshold: float|null, rescue: array{from: string, to: string}|null} $first
     * @param array{result: SearchResult, statements: list<array{label: string, sql: string, params: array<string, scalar|null>}>, threshold: float|null, rescue: array{from: string, to: string}|null} $second
     * @param array{from: string, to: string} $rescue the typed query and the corrected one, both as the parser reads them (bounded, sanitised)
     *
     * @return array{result: SearchResult, statements: list<array{label: string, sql: string, params: array<string, scalar|null>}>, threshold: float|null, rescue: array{from: string, to: string}|null}
     */
    private function rescued(SearchQuery $query, array $first, array $second, array $rescue, int|float $started): array
    {
        $inner = $second['result'];

        return [
            'result' => new SearchResult(
                hits: $inner->hits,
                total: $inner->total,
                totalIsLowerBound: $inner->totalIsLowerBound,
                tookMs: round((hrtime(true) - $started) / 1e6, 3),
                usedFuzzy: $inner->usedFuzzy,
                warnings: array_values(array_unique([...$first['result']->warnings, ...$inner->warnings, sprintf("No results for '%s'; showing results for '%s'.", $rescue['from'], $rescue['to'])])),
                limit: $inner->limit,
                offset: $inner->offset,
                interpretedAs: $inner->interpretedAs,
                didYouMean: null,
                facets: $inner->facets,
                correctedFrom: $query->text,
            ),
            'statements' => [...$first['statements'], ...self::rescueLabels($second['statements'])],
            'threshold' => $second['threshold'],
            'rescue' => null,
        ];
    }

    /**
     * @param list<array{label: string, sql: string, params: array<string, scalar|null>}> $statements
     *
     * @return list<array{label: string, sql: string, params: array<string, scalar|null>}>
     */
    private static function rescueLabels(array $statements): array
    {
        return array_map(static fn(array $statement): array => ['label' => self::RESCUE_PREFIX . $statement['label']] + $statement, $statements);
    }

    /**
     * One search without a rescue of its own (`$rescue` false, the run of a rescue, also has no relaxation and no suggestion: see above). A search is to be rescued ("rescue" is set) when it found nothing (after the
     * relaxation and the typo-tolerant branch), $rescue allows it (the second run of a rescue does not), `rescue_empty` and
     * `did_you_mean` are on, typo tolerance is not switched off (`fuzzy_mode: never`, a profile with `fuzzy` 0), the text is not a search-as-you-type one (the last word is unfinished: nothing is wrong with it),
     * the viewer is not restricted and the index is not tenant-scoped (the vocabulary is the words of every document,
     * hidden fields and other tenants' included: suggest() never looks it up for them), and every word that needs a
     * correction has exactly one vocabulary word one edit away (suggest()). "from" and "to" are the typed and the corrected
     * query as the parser reads them.
     *
     * @return array{
     *     result: SearchResult,
     *     statements: list<array{label: string, sql: string, params: array<string, scalar|null>}>,
     *     threshold: float|null,
     *     rescue: array{from: string, to: string}|null,
     *     relaxed: bool
     * }
     */
    private function executeOnce(IndexDefinition $index, SearchQuery $query, bool $rescue): array
    {
        $started = hrtime(true);
        if ($index->tenant !== null && $query->tenant === null) {
            throw InvalidQuery::missingTenant($index->name);
        }
        if ($index->tenant === null && $query->tenant !== null) {
            throw InvalidQuery::unexpectedTenant($index->name);
        }
        $conditions = $index->tenant !== null
            ? [new Condition($index->tenant, Operator::Eq, $query->tenant), ...$query->conditions]
            : $query->conditions;

        $thresholds = $index->thresholds->with($query->thresholdOverrides);
        if (!$rescue) {
            // the run of a rescue answers with its hits and total only: the relaxation, the suggestion and a second rescue are work it would throw away
            $thresholds = $thresholds->with(['relax_when_empty' => false, 'did_you_mean' => false]);
        }
        $profile = $query->rankingOverrides === [] ? $index->profile($query->profile) : $index->profile($query->profile)->with($query->rankingOverrides);
        (new FilterCompiler($index))->validate(...$conditions);
        foreach ($query->facets as $facet) {
            $index->assertFacetable($facet);
        }
        // every requested name, before a restricted viewer's hidden ones are dropped: the same request fails or passes for every viewer
        Highlighter::assertHighlightable($index, $query->highlight);

        $parsed = (new QueryParser($thresholds->maxQueryLength, $thresholds->maxTerms))->parse($query->text);
        $warnings = $parsed->warnings;
        $root = $parsed->root;
        if ($root === null && trim($query->text) !== '') {
            // Text was typed but nothing searchable survived parsing ("***"): browsing would list the whole index.
            $warnings[] = 'The search has no word to look for; use letters or digits.';
            $empty = SearchResult::empty($query->limit, $query->offset, $warnings, round((hrtime(true) - $started) / 1e6, 3));

            return ['result' => $empty, 'statements' => [], 'threshold' => null, 'rescue' => null, 'relaxed' => false];
        }
        if ($root !== null && !NodeInspector::hasPositive($root)) {
            // "-cable" alone would mean "everything except ..." which is rarely intended and never cheap.
            $warnings[] = 'The search only excluded words; add at least one word to look for.';
            $empty = SearchResult::empty($query->limit, $query->offset, $warnings, round((hrtime(true) - $started) / 1e6, 3));

            return ['result' => $empty, 'statements' => [], 'threshold' => null, 'rescue' => null, 'relaxed' => false];
        }

        $typedRoot = $root;
        if ($root !== null && !$index->synonyms->isEmpty()) {
            $root = $this->expandSynonyms($index, $root, $thresholds, $warnings);
        }
        $expandedRoot = $root;

        $run = $this->pipeline($index, $root, $conditions, $profile, $thresholds, $query, '', true);
        // What is left of the search (the typo-tolerant statements, the relaxation, the facets, the suggestion) runs in ONE transaction
        // when typo tolerance is due (see inSearchTransaction()); a search that needs no setting has no transaction at all.
        $rest = function () use ($index, $query, $conditions, $profile, $thresholds, $typedRoot, $expandedRoot, $root, $warnings, $run, $rescue): array {
            $run = $this->completed($run);
            $statements = $run['statements'];
            array_push($warnings, ...$run['warnings']);
            // setting of the last search statement, which explain() runs again
            $threshold = $run['threshold'];

            // Empty-result relaxation: drop the words that match nothing on their own, search once more.
            $relaxedUsed = false;
            if ($root !== null && $thresholds->relaxWhenEmpty && self::total($run['rows']) === 0) {
                $probe = $this->probe($index, $root, $run['fuzzy'], $run['emptyQueries'], $conditions, $thresholds, $query->allowedFields);
                if ($probe !== null) {
                    $statements[] = $probe['statement'];
                    $reduced = $probe['ignored'] === [] ? null : Relaxation::without($root, $probe['ignored']);
                    // the same guard as for the query itself: a relaxed query must still look for something
                    if ($reduced !== null && NodeInspector::hasPositive($reduced)) {
                        $relaxed = $this->pipeline($index, $reduced, $conditions, $profile, $thresholds, $query, 'relaxed: ');
                        array_push($statements, ...$relaxed['statements']);
                        $threshold = $relaxed['threshold'];
                        // Still nothing: the user would be told words were ignored and see no result anyway,
                        // so the original (empty) answer stands.
                        if (self::total($relaxed['rows']) > 0) {
                            $root = $reduced;
                            $relaxedUsed = true;
                            $run = $relaxed;
                            array_push($warnings, ...$relaxed['warnings']);
                            $warnings[] = Relaxation::warning($probe['ignored']);
                        }
                    }
                }
            }

            $rows = $run['rows'];
            $tsquery = $run['tsquery'];
            $total = self::total($rows);
            // both branches select `window` candidates (more than the candidate limit for a page past it)
            $window = SearchSqlBuilder::window($thresholds, $query->limit, $query->offset);
            $capped = $rows !== [] && (Coerce::int($rows[0]['fts_n']) >= $window || Coerce::int($rows[0]['fuzzy_n']) >= $window);
            if ($run['browse']) {
                $capped = $total >= $window;
            }
            $builder = new SearchSqlBuilder($index, $this->names, $run['ranked']['candidates'] ?? [], $query->allowedFields, true, cappedColumn: $this->cappedColumn($index));
            // exactCounts(): when the candidate limit cut the matches off, count them all
            if ($query->exactCounts && $capped) {
                $statement = ['label' => self::EXACT_LABEL] + ($run['ranked'] === null
                    ? $builder->browseExactTotal($conditions)
                    : $builder->exactTotal($run['ranked']['tsquery'], $run['ranked']['plain'], $run['ranked']['fuzzyRoot'], $conditions, $profile, $thresholds, $run['ranked']['emptyQueries'], $run['ranked']['scopedRoot']));
                $total = Coerce::int($this->run($statement, $threshold)[0]['total'] ?? 0);
                $statements[] = $statement;
                $capped = false;
            }
            $facets = [];
            // disjunctive: the conditions on a facet's own filter are left out, so it shows what another value would find (the tenant's stay)
            $others = [];
            foreach ($query->facets as $name) {
                $others[$name] = array_values(array_filter($conditions, static fn(Condition $c): bool => $c->filter !== $name || $c->filter === $index->tenant));
            }
            // the facets that keep every condition count the very candidates of the search: two or more of them share one statement
            $common = array_values(array_unique(array_filter($query->facets, static fn(string $name): bool => $others[$name] === $conditions)));
            $facetRows = [];
            $ids = $run['ranked'] !== null && $common !== [] ? self::candidateIds($rows) : null;
            if ($ids !== null && count($common) > 1) {
                $statement = ['label' => self::FACET_LABEL . implode(', ', $common)] + $builder->facetsOf($ids, $common, $query->facetValues);
                $facetRows = array_fill_keys($common, []);
                foreach ($this->run($statement, null) as $row) { // ids: it reads no similarity setting
                    $facetRows[$common[Coerce::int($row['f'])]][] = $row;
                }
                $statements[] = $statement;
            } elseif (count($common) > 1) {
                $statement = ['label' => self::FACET_LABEL . implode(', ', $common)] + ($run['ranked'] === null
                    ? $builder->browseFacets($common, $conditions, $thresholds, $query->facetValues, $query->exactCounts)
                    : $builder->facets($common, $run['ranked']['tsquery'], $run['ranked']['plain'], $run['ranked']['fuzzyRoot'], $conditions, $profile, $thresholds, $query->facetValues, $query->exactCounts, $run['ranked']['emptyQueries'], $run['ranked']['scopedRoot']));
                $facetRows = array_fill_keys($common, []);
                foreach ($this->run($statement, $threshold) as $row) {
                    $facetRows[$common[Coerce::int($row['f'])]][] = $row;
                }
                $statements[] = $statement;
            }
            foreach ($query->facets as $name) {
                if (!isset($facetRows[$name]) && $ids !== null && $others[$name] === $conditions) {
                    // the facet counts the very candidates of the page
                    $statement = ['label' => self::FACET_LABEL . $name] + $builder->facetsOf($ids, [$name], $query->facetValues);
                    $facetRows[$name] = $this->run($statement, null);
                    $statements[] = $statement;
                } elseif (!isset($facetRows[$name])) {
                    // the conditions differ from the search's: the facet needs its own candidates
                    // the probed candidates passed every condition: a facet that leaves one out keeps the words' trigram condition
                    $facetBuilder = $others[$name] === $conditions ? $builder : new SearchSqlBuilder($index, $this->names, [], $query->allowedFields, true, cappedColumn: $this->cappedColumn($index));
                    $statement = ['label' => self::FACET_LABEL . $name] + ($run['ranked'] === null
                        ? $facetBuilder->browseFacet($name, $others[$name], $thresholds, $query->facetValues, $query->exactCounts)
                        : $facetBuilder->facet($name, $run['ranked']['tsquery'], $run['ranked']['plain'], $run['ranked']['fuzzyRoot'], $others[$name], $profile, $thresholds, $query->facetValues, $query->exactCounts, $run['ranked']['emptyQueries'], $run['ranked']['scopedRoot']));
                    $facetRows[$name] = $this->run($statement, $threshold);
                    $statements[] = $statement;
                }
                $type = $index->filter($name)->type;
                $facets[$name] = array_map(static fn(array $row): FacetValue => new FacetValue(self::facetValue($type, $row['value'] ?? null), Coerce::int($row['n'])), $facetRows[$name]);
            }
            $rows = self::hitsOnly($rows);
            $suggestion = null;
            $oneEdit = null;
            // the vocabulary holds the words of every field: a restricted viewer gets no suggestion (nor the lookup's statement)
            $didYouMean = $thresholds->didYouMean && $query->allowedFields === null && $typedRoot !== null && $expandedRoot !== null && !$run['browse'] && $index->hasFuzzy() && ($total < $thresholds->fallbackBelow || $run['usedFuzzy'])
                ? $this->suggest($index, $typedRoot, $expandedRoot, $thresholds, $suggestion, $oneEdit)
                : null;
            if ($suggestion !== null) {
                $statements[] = $suggestion;
            }
            // Only a search that found nothing is rescued, and a search-as-you-type text never (its last word is not finished). It is
            // a typo correction: a search that switched typo tolerance off (fuzzy_mode never, a profile without fuzzy weight) has none.
            $rescueWith = $rescue && $total === 0 && $thresholds->rescueEmpty && $thresholds->fuzzyMode !== FuzzyMode::Never && $profile->fuzzy > 0.0 && !$query->asYouType && $typedRoot !== null && $oneEdit !== null
                ? ['from' => QueryRenderer::render($typedRoot), 'to' => $oneEdit]
                : null;

            return [$run, $statements, $warnings, $threshold, $root, $rows, $tsquery, $total, $capped, $facets, $didYouMean, $rescueWith, $relaxedUsed];
        };
        [$run, $statements, $warnings, $threshold, $root, $rows, $tsquery, $total, $capped, $facets, $didYouMean, $rescueWith, $relaxedUsed] = $run['phase'] !== null ? $this->inSearchTransaction($rest) : $rest();

        $highlights = [];
        // a field the viewer may not see is dropped from the highlights (not an error: the application asks the same for everyone)
        $highlighted = $query->allowedFields === null ? $query->highlight : array_values(array_intersect($query->highlight, $query->allowedFields));
        if ($highlighted !== [] && $tsquery !== null && $rows !== []) {
            $headline = $tsquery;
            // a hit without text rank was not found by the strict branch but by typo tolerance: ts_headline marks none of the words that were mistyped
            $typo = null;
            if ($run['usedFuzzy'] && $root !== null) {
                $typoIds = [];
                foreach ($rows as $r) {
                    if (Coerce::float($r['r_text']) <= 0.0) {
                        $typoIds[] = $index->idType->cast(Coerce::str($r['id']));
                    }
                }
                $terms = $typoIds === [] ? [] : (new FuzzyQueryCompiler($index, $thresholds, $this->names, [], $query->allowedFields, true))->typoTerms($root, $run['emptyQueries'] ?? []);
                $typo = $terms === [] ? null : new TypoPlan($terms, $typoIds, $thresholds->fuzzySimilarity, $query->allowedFields);
            }
            $highlights = $this->guard('highlighting', fn(): array => (new Highlighter($this->connection, $this->names))->highlight(
                $index,
                $highlighted,
                $headline,
                array_map(static fn(array $r): int|string => $index->idType->cast(Coerce::str($r['id'])), $rows),
                $typo,
            ), 'Run "bin/console fuzzphony:doctor" to check the index.');
        }

        $hits = [];
        foreach ($rows as $row) {
            $id = $index->idType->cast(Coerce::str($row['id']));
            $hits[] = new Hit($id, Coerce::float($row['score']), new ScoreBreakdown(
                textRank: Coerce::float($row['r_text']),
                fuzzySimilarity: Coerce::float($row['r_fuzzy']),
                relevance: Coerce::float($row['relevance']),
                exactBonus: Coerce::float($row['exact_bonus']),
                prefixBonus: Coerce::float($row['prefix_bonus']),
                boostBonus: Coerce::float($row['boost_bonus']),
                recencyBonus: Coerce::float($row['recency_bonus']),
            ), $highlights[(string) $id] ?? []);
        }

        $result = new SearchResult(
            hits: $hits,
            total: $total,
            totalIsLowerBound: $capped,
            tookMs: round((hrtime(true) - $started) / 1e6, 3),
            usedFuzzy: $run['usedFuzzy'],
            warnings: array_values(array_unique($warnings)),
            limit: $query->limit,
            offset: $query->offset,
            interpretedAs: $root !== null ? (string) $root : null,
            didYouMean: $didYouMean,
            facets: $facets,
        );

        return ['result' => $result, 'statements' => $statements, 'threshold' => $threshold, 'rescue' => $rescueWith, 'relaxed' => $relaxedUsed];
    }

    /** A facet value as PHP: the type the filter declares. */
    private static function facetValue(FilterType $type, mixed $value): bool|int|string|null
    {
        return match (true) {
            $value === null => null,
            $type === FilterType::Bool => $value === true || $value === 't' || $value === '1' || $value === 1,
            $type === FilterType::Int => Coerce::int($value),
            default => Coerce::str($value),
        };
    }

    /**
     * The query with the words it probably meant, for a search that found few hits: a whole word the
     * vocabulary does not have (and that is not a stop word, nor one a synonym expanded) is replaced
     * by the vocabulary word nearest to it. The trigram index picks the ten closest candidates, then
     * the edit distance decides (a trigram ranking alone suggests `most` for `mose`), then how many
     * documents have the word; nothing farther than a third of the word's length away is suggested.
     * Null when no word has a better one. $statement is set to the statement that looked the words up, for explain().
     *
     * $oneEdit is set, from the same rows, to the query for the zero-result rescue (see executeOnce()): the typed query with every
     * word that has a vocabulary word exactly one edit away replaced by it, and null unless each of those words has exactly one
     * such word among the closest candidates (a tie, `mose` for `mouse` or `moose`, means no rescue) and at least one word has one.
     * Words with no one-edit candidate stay as typed.
     *
     * @param array{label: string, sql: string, params: array<string, scalar|null>}|null $statement
     */
    private function suggest(IndexDefinition $index, Node $typed, Node $expanded, Thresholds $thresholds, ?array &$statement = null, ?string &$oneEdit = null): ?string
    {
        // the vocabulary is the words of the whole index: on a tenant-scoped one a suggestion (or its absence) would tell
        // one customer which words another customer's documents have
        if ($index->tenant !== null) {
            return null;
        }
        $skip = SynonymExpander::expandedWords($expanded);
        $words = [];
        foreach (NodeInspector::suggestibleWords($typed) as $word) {
            $lower = mb_strtolower($word);
            // the index's own minimum, whatever this query set: shorter words were never put in the vocabulary;
            // a word with a digit is a code (rtx4090), and the nearest code is not what the user meant
            if (mb_strlen($lower) >= max($thresholds->fuzzyMinLength, $index->thresholds->fuzzyMinLength) && preg_match('/\d/u', $lower) !== 1 && !in_array($lower, $skip, true)) {
                $words[$lower] = true;
            }
        }
        $words = array_slice(array_keys($words), 0, $thresholds->maxTerms);
        if ($words === []) {
            return null;
        }
        $vocabulary = $this->names->vocabulary($index);
        $extension = $this->names->extension();
        $restore = $this->settings === null && (!($this->connection instanceof TransactionAware) || $this->connection->inTransaction());
        // A table the schema has not created yet, or one this role may not read (an upgrade before "fuzzphony:schema --apply"
        // and the GRANT), is no reason to fail a search, and a failed statement would abort a transaction the caller may have open.
        // A positive answer is kept for the life of the engine (resetCaches() forgets it), a negative one never, so the check is one
        // round trip per process, not per search. It is not trusted when the caller may have a transaction open: a lookup that fails
        // there (the table dropped or the privilege revoked since) would abort it, so that case asks every time, as it always did.
        if ($restore || !isset($this->vocabularyReadable[$vocabulary])) {
            if ($this->connection->fetchValue("SELECT to_regclass(:a) IS NOT NULL AND has_table_privilege(to_regclass(:b), 'SELECT')", ['a' => $vocabulary, 'b' => $vocabulary]) !== true) {
                return null;
            }
            $this->vocabularyReadable[$vocabulary] = true;
        }
        // known: a stop word, a word of the vocabulary, or any word the index finds (a word of a field that is not typo-tolerant,
        // an inflection, a word added since the last full reindex): the search above found what it could
        // The candidates of a word: the ten closest vocabulary words by trigram similarity and, whatever their similarity, the vocabulary
        // words it becomes with two neighbouring letters swapped (a swap in a short word leaves few trigrams: `mosue` shares two of ten with `mouse`)
        $sql = sprintf(
            "SELECT q.w, q.n, (to_tsvector(%2\$s, q.w) = ''::tsvector OR EXISTS (SELECT 1 FROM %1\$s AS v WHERE v.word = q.n) OR EXISTS (SELECT 1 FROM %5\$s AS s WHERE s.tsv @@ plainto_tsquery(%2\$s, q.w))) AS known, c.word, c.freq FROM (SELECT t.w, %3\$s(t.w) AS n, t.v FROM unnest(string_to_array(:words, chr(31)), string_to_array(:variants, chr(29))) AS t(w, v)) AS q LEFT JOIN LATERAL ((SELECT v.word, v.freq FROM %1\$s AS v WHERE v.word OPERATOR(%4\$s.%%) q.n ORDER BY %4\$s.similarity(v.word, q.n) DESC, v.freq DESC LIMIT 10) UNION (SELECT v.word, v.freq FROM %1\$s AS v WHERE v.word = ANY(string_to_array(q.v, chr(30))))) AS c ON true",
            $vocabulary,
            $this->names->regconfig($index->text),
            $this->names->normFunction(),
            $extension,
            $this->names->sidecar($index),
        );
        $parameters = [
            'words' => implode(chr(31), $words),
            'variants' => implode(chr(29), array_map(static fn(string $word): string => implode(chr(30), Transpositions::of($word)), $words)),
        ];
        $statement = ['label' => self::SUGGEST_LABEL, 'sql' => $sql, 'params' => $parameters];
        // the candidates are the words with a trigram similarity of SUGGEST_SIMILARITY or more, whatever the session has set
        /** @var \Closure(): list<array<string, mixed>> $lookup */
        $lookup = fn() => $this->fetchWithSetting($sql, $parameters, self::SUGGEST_SIMILARITY, 'pg_trgm.similarity_threshold');
        try {
            $rows = $this->guard('did_you_mean', $lookup, 'Run "bin/console fuzzphony:schema --apply", then "bin/console fuzzphony:reindex --vocabulary".');
        } catch (FuzzphonyException $e) {
            // the table may be gone (or unreadable) since it was last seen: the next search asks again
            unset($this->vocabularyReadable[$vocabulary]);

            throw $e;
        }

        /** @var array<string, array{int, int, string}> $best typed word => [distance, -documents, vocabulary word] */
        $best = [];
        /** @var array<string, array<string, true>> $ones typed word => the vocabulary words one edit away */
        $ones = [];
        foreach ($rows as $row) {
            $word = Coerce::str($row['w']);
            if (in_array($row['known'], [true, 't', 'true', 1, '1'], true)) {
                continue;
            }
            if ($row['word'] === null) {
                continue;
            }
            $normalised = Coerce::str($row['n']);
            $candidate = Coerce::str($row['word']);
            $distance = self::distance($normalised, $candidate);
            if ($distance < 1 || $distance > max(1, intdiv(mb_strlen($normalised), 3))) {
                continue;
            }
            if ($distance === 1) {
                $ones[$word][$candidate] = true;
            }
            $rank = [$distance, -Coerce::int($row['freq']), $candidate];
            if (!isset($best[$word]) || $rank < $best[$word]) {
                $best[$word] = $rank;
            }
        }
        if ($ones !== [] && array_all($ones, static fn(array $candidates): bool => count($candidates) === 1)) {
            $oneEdit = QueryRenderer::render($typed, array_map(static fn(array $candidates): string => (string) array_key_first($candidates), $ones));
        }
        $replace = array_map(static fn(array $rank): string => $rank[2], $best);
        if ($replace === []) {
            return null;
        }

        return QueryRenderer::render($typed, $replace);
    }

    /**
     * The edit distance of two words by their characters (PHP's levenshtein() counts bytes), counting two neighbouring letters
     * swapped as one edit (optimal string alignment: a typo the search itself finds, `mosue` for `mouse`).
     */
    private static function distance(string $a, string $b): int
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $n = count($x);
        $m = count($y);
        $previous = [];
        $row = range(0, $m);
        for ($i = 1; $i <= $n; ++$i) {
            $next = [$i];
            for ($j = 1; $j <= $m; ++$j) {
                $next[$j] = min($row[$j] + 1, $next[$j - 1] + 1, $row[$j - 1] + ($x[$i - 1] === $y[$j - 1] ? 0 : 1));
                if ($i > 1 && $j > 1 && $x[$i - 1] === $y[$j - 2] && $x[$i - 2] === $y[$j - 1]) {
                    $next[$j] = min($next[$j], $previous[$j - 2] + 1);
                }
            }
            $previous = $row;
            $row = $next;
        }

        return $row[$m];
    }

    /** @param list<string> $words lowercase words to stem for $config; the stems land in the per-configuration cache */
    private function fetchStems(string $config, array $words): void
    {
        if ($words === []) {
            return;
        }
        $rows = $this->guard('synonyms', fn(): array => $this->connection->fetchAll(
            sprintf("SELECT w, CASE WHEN w ~ '^[[:alnum:]]+\$' THEN coalesce(nullif(array_to_string(tsvector_to_array(to_tsvector(%s, w)), ' '), ''), w) ELSE w END AS s FROM unnest(string_to_array(:words, chr(31))) AS w", $config),
            ['words' => implode(chr(31), $words)],
        ), 'Run "bin/console fuzzphony:doctor" to check the index.');
        foreach ($rows as $row) {
            $this->stems[$config][Coerce::str($row['w'])] = Coerce::str($row['s']);
        }
    }

    public function stemSynonyms(IndexDefinition $index, Synonyms $synonyms): Synonyms
    {
        $config = $this->names->regconfig($index->text);
        $words = array_values(array_unique(SynonymExpander::wordsOf($synonyms)));
        $stems = [];
        // in batches, so a very long list is not one huge parameter
        foreach (array_chunk($words, 5_000) as $batch) {
            $this->stems[$config] = [];
            $this->fetchStems($config, $batch);
            $stems += $this->stems[$config];
        }
        $this->stems[$config] = [];

        return $synonyms->withStems($config, $stems);
    }

    /**
     * The query with the index's synonyms. PostgreSQL stems the words (the index's text configuration,
     * accents folded; a word with a symbol or a hyphen, `c++`, `wi-fi`, is compared as it is, because
     * PostgreSQL reduces `c++` and `c#` to the same lexeme) in one round trip, and only the words this engine has not seen yet: the stems
     * of the synonyms are fetched once, with the first search, and every expander is kept. The query
     * gets at most four times `max_terms` alternatives, so a large synonym group cannot make a
     * statement huge; a word that would go over is left as typed, with a warning.
     *
     * @param list<string> $warnings
     */
    private function expandSynonyms(IndexDefinition $index, Node $root, Thresholds $thresholds, array &$warnings): Node
    {
        $queryWords = SynonymExpander::queryWords($root);
        if ($queryWords === []) {
            return $root;
        }
        $config = $this->names->regconfig($index->text);
        // the key follows the content of the synonyms (an equal list given again shares the expander); the digest of a long list
        // costs about a millisecond, so it is made once per (immutable) Synonyms object, not on every search
        $this->synonymDigests ??= new \WeakMap();
        $digest = $this->synonymDigests[$index->synonyms] ??= md5(serialize($index->synonyms->toEntries()));
        $key = $index->name . '|' . $config . '|' . $digest;
        if (count($this->stems[$config] ?? []) > self::STEM_CACHE) {
            $this->stems[$config] = [];
        }
        // prepared stems (Fuzzphony::stemSynonyms()) spare the round trip for the members
        $prepared = $index->synonyms->stemConfig === $config;
        $memberWords = isset($this->expanders[$key]) || $prepared ? [] : SynonymExpander::wordsOf($index->synonyms);
        $missing = array_values(array_unique(array_filter([...$memberWords, ...$queryWords], fn(string $word): bool => !isset($this->stems[$config][$word]))));
        $this->fetchStems($config, $missing);
        $this->expanders[$key] ??= new SynonymExpander($index->synonyms, $prepared ? $index->synonyms->stems : array_intersect_key($this->stems[$config] ?? [], array_flip($memberWords)));
        $truncated = false;
        $expanded = $this->expanders[$key]->expand($root, array_intersect_key($this->stems[$config] ?? [], array_flip($queryWords)), $thresholds->maxTerms * 4, $truncated);
        if ($truncated) {
            $warnings[] = 'Synonyms were expanded for part of the query only (too many alternatives).';
        }

        return $expanded;
    }

    /**
     * The normal search: strict full text, then the typo-tolerant branch as the fuzzy mode says
     * (or browsing when there is no text). Runs once, or twice when an empty result is relaxed.
     *
     * The typo-tolerant branch (the per-word candidate probes and the ranked statement it ends with) is a "phase". With
     * $defer it is not run but returned in "phase", with the strict statement's rows (none when typo tolerance comes first)
     * as they are: the caller runs the rest of the search inside one transaction (see completed()). Without $defer the
     * phase runs here, in a transaction of its own.
     *
     * @param list<Condition> $conditions
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     statements: list<array{label: string, sql: string, params: array<string, scalar|null>}>,
     *     usedFuzzy: bool,
     *     threshold: float|null,
     *     tsquery: string|null,
     *     fuzzy: bool,
     *     emptyQueries: list<string>|null,
     *     browse: bool,
     *     warnings: list<string>,
     *     ranked: array{tsquery: string|null, plain: string, fuzzyRoot: Node|null, emptyQueries: list<string>, scopedRoot: Node|null, candidates: array<string, list<int|string>>}|null,
     *     phase: (\Closure(): array{rows: list<array<string, mixed>>, statements: list<array{label: string, sql: string, params: array<string, scalar|null>}>, threshold: float|null, ranked: array{tsquery: string|null, plain: string, fuzzyRoot: Node|null, emptyQueries: list<string>, scopedRoot: Node|null, candidates: array<string, list<int|string>>}})|null
     * }
     */
    private function pipeline(IndexDefinition $index, ?Node $root, array $conditions, RankingProfile $profile, Thresholds $thresholds, SearchQuery $query, string $labelPrefix, bool $defer = false): array
    {
        $warnings = [];
        $tsquery = null;
        $scopedRoot = null;
        if ($root !== null) {
            $compiler = new TsQueryCompiler($index);
            $tsquery = $compiler->compile($root);
            $warnings = $compiler->warnings();
            // a word scoped to a field the index has is rechecked against that field's own column
            // (a restricted viewer's every word against the allowed fields', see SearchSqlBuilder)
            // (so is a phrase on an index with several fields: tsv caps its positions, see SearchSqlBuilder)
            $scopedRoot = $compiler->hasFieldScope($root) || $query->allowedFields !== null || (count($index->fields) > 1 && $compiler->hasSequence($root)) ? $root : null;
        }
        $plain = implode(' ', TsQueryCompiler::lexemes(implode(' ', NodeInspector::positiveWords($root))));

        // Typo tolerance is per word (FuzzyQueryCompiler); it needs at least one positive word long enough for it.
        $fuzzy = new FuzzyQueryCompiler($index, $thresholds, $this->names, [], $query->allowedFields, true, cappedColumn: $this->cappedColumn($index));
        $fuzzyRoot = $root !== null
            && $index->hasFuzzy()
            && $profile->fuzzy > 0.0
            && $thresholds->fuzzyMode !== FuzzyMode::Never
            && $fuzzy->hasFuzzyLeaf($root) ? $root : null;

        // "the who -rock": PostgreSQL reduces 'the' & 'who' & !'rock' to !'rock', "everything but rock", so the strict branch
        // gets the stop words alone (as for "the who": nothing matches) and the typo-tolerant branch searches them, rock excluded
        if ($fuzzyRoot instanceof AllOf && $tsquery !== null && array_any($fuzzyRoot->nodes, static fn(Node $n): bool => $n instanceof Not)
            && ($stopWords = $fuzzy->exclusionStopWords($fuzzyRoot, $this->emptyQueries($index, $fuzzy->leafQueries($fuzzyRoot)))) !== null) {
            $tsquery = (new TsQueryCompiler($index))->compile($stopWords);
        }

        $builder = new SearchSqlBuilder($index, $this->names, [], $query->allowedFields, true, cappedColumn: $this->cappedColumn($index));
        // the facets that keep every condition count the very candidates of the page's own selection (see SearchSqlBuilder::facetsOf()), unless
        // the page is past the candidate limit (its selection is larger, and the facets of a search must not change with the page) or the
        // counts are exact (they read every match)
        $withIds = $query->facets !== [] && !$query->exactCounts && SearchSqlBuilder::window($thresholds, $query->limit, $query->offset) === $thresholds->candidateLimit;
        /** @var list<array{label: string, sql: string, params: array<string, scalar|null>}> $statements */
        $statements = [];
        $threshold = null;
        $browse = false;
        $ranked = null;
        $phase = null;
        $rows = [];
        // the recheck drops stop words as the strict tsquery does, so it needs them up front
        $emptyQueries = $scopedRoot === null ? null : $this->fuzzyStopWords($index, $fuzzy, $scopedRoot, $fuzzyRoot);
        // the typo-tolerant statements: the candidates of each word probed on its own, then the ranked statement over them
        $typoPhase = fn(Node $fuzzyRoot, string $label, array $empty): \Closure => fn(): array => $this->inSearchTransaction(function () use ($index, $fuzzy, $fuzzyRoot, $label, $empty, $conditions, $profile, $thresholds, $query, $tsquery, $plain, $scopedRoot, $withIds): array {
            /** @var list<string> $empty */
            $candidates = $this->fuzzyCandidates($fuzzy, $fuzzyRoot, $empty, $thresholds, $conditions);
            $statement = ['label' => $label]
                + (new SearchSqlBuilder($index, $this->names, $candidates, $query->allowedFields, true, cappedColumn: $this->cappedColumn($index)))->ranked($tsquery, $plain, $fuzzyRoot, $conditions, $profile, $thresholds, $query->limit, $query->offset, $empty, $scopedRoot, $withIds);
            $threshold = $fuzzy->lowestSimilarity($fuzzyRoot, $empty);
            $rows = $this->run($statement, $threshold);
            $statements = [$statement];

            return [
                'rows' => $rows,
                'statements' => $statements,
                'threshold' => $threshold,
                'ranked' => ['tsquery' => $tsquery, 'plain' => $plain, 'fuzzyRoot' => $fuzzyRoot, 'emptyQueries' => $empty, 'scopedRoot' => $scopedRoot, 'candidates' => $candidates],
            ];
        });

        if ($tsquery === null && $plain === '') {
            $statement = ['label' => 'browse'] + $builder->browse($conditions, $profile, $thresholds, $query->limit, $query->offset);
            $rows = $this->run($statement, null);
            $statements[] = $statement;
            $browse = true;
        } else {
            $alwaysFuzzy = $fuzzyRoot !== null
                && ($thresholds->fuzzyMode === FuzzyMode::Always || $tsquery === null)
                && $fuzzy->hasFuzzyLeaf($fuzzyRoot, $emptyQueries ??= $this->fuzzyStopWords($index, $fuzzy, $fuzzyRoot, $fuzzyRoot));
            if ($alwaysFuzzy && $fuzzyRoot !== null) {
                $phase = $typoPhase($fuzzyRoot, $labelPrefix . 'full-text + fuzzy', $emptyQueries ?? []);
            } else {
                $statement = ['label' => $labelPrefix . 'full-text'] + $builder->ranked($tsquery, $plain, null, $conditions, $profile, $thresholds, $query->limit, $query->offset, $emptyQueries ?? [], $scopedRoot, $withIds);
                $rows = $this->run($statement, null);
                $statements[] = $statement;
                $ranked = ['tsquery' => $tsquery, 'plain' => $plain, 'fuzzyRoot' => null, 'emptyQueries' => $emptyQueries ?? [], 'scopedRoot' => $scopedRoot, 'candidates' => []];

                if (
                    $fuzzyRoot !== null
                    && $thresholds->fuzzyMode === FuzzyMode::Fallback
                    && self::total($rows) < $thresholds->fallbackBelow
                    && $fuzzy->hasFuzzyLeaf($fuzzyRoot, $emptyQueries ??= $this->fuzzyStopWords($index, $fuzzy, $fuzzyRoot, $fuzzyRoot))
                ) {
                    $phase = $typoPhase($fuzzyRoot, $labelPrefix . 'fallback: full-text + fuzzy', $emptyQueries);
                }
            }
        }

        $run = [
            'rows' => $rows,
            'statements' => $statements,
            'usedFuzzy' => false,
            'threshold' => $threshold,
            'tsquery' => $tsquery,
            'fuzzy' => $fuzzyRoot !== null,
            'emptyQueries' => $emptyQueries,
            'browse' => $browse,
            'warnings' => $warnings,
            'ranked' => $ranked,
            'phase' => $phase,
        ];

        return $defer ? $run : $this->completed($run);
    }

    /**
     * A pipeline result with its typo-tolerant phase run (see pipeline()): the phase's rows, statements, setting and
     * ranked statement replace the strict statement's, as they always did. A result without a phase is returned as it is.
     *
     * @param array{
     *     rows: list<array<string, mixed>>,
     *     statements: list<array{label: string, sql: string, params: array<string, scalar|null>}>,
     *     usedFuzzy: bool,
     *     threshold: float|null,
     *     tsquery: string|null,
     *     fuzzy: bool,
     *     emptyQueries: list<string>|null,
     *     browse: bool,
     *     warnings: list<string>,
     *     ranked: array{tsquery: string|null, plain: string, fuzzyRoot: Node|null, emptyQueries: list<string>, scopedRoot: Node|null, candidates: array<string, list<int|string>>}|null,
     *     phase: (\Closure(): array{rows: list<array<string, mixed>>, statements: list<array{label: string, sql: string, params: array<string, scalar|null>}>, threshold: float|null, ranked: array{tsquery: string|null, plain: string, fuzzyRoot: Node|null, emptyQueries: list<string>, scopedRoot: Node|null, candidates: array<string, list<int|string>>}})|null
     * } $run
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     statements: list<array{label: string, sql: string, params: array<string, scalar|null>}>,
     *     usedFuzzy: bool,
     *     threshold: float|null,
     *     tsquery: string|null,
     *     fuzzy: bool,
     *     emptyQueries: list<string>|null,
     *     browse: bool,
     *     warnings: list<string>,
     *     ranked: array{tsquery: string|null, plain: string, fuzzyRoot: Node|null, emptyQueries: list<string>, scopedRoot: Node|null, candidates: array<string, list<int|string>>}|null,
     *     phase: (\Closure(): array{rows: list<array<string, mixed>>, statements: list<array{label: string, sql: string, params: array<string, scalar|null>}>, threshold: float|null, ranked: array{tsquery: string|null, plain: string, fuzzyRoot: Node|null, emptyQueries: list<string>, scopedRoot: Node|null, candidates: array<string, list<int|string>>}})|null
     * }
     */
    private function completed(array $run): array
    {
        if ($run['phase'] === null) {
            return $run;
        }
        $phase = ($run['phase'])();

        return [
            'rows' => $phase['rows'],
            'statements' => [...$run['statements'], ...$phase['statements']],
            'usedFuzzy' => true,
            'threshold' => $phase['threshold'],
            'ranked' => $phase['ranked'],
            'phase' => null,
        ] + $run;
    }

    /**
     * The relaxation probe: which positive words match no document of the searched set on their
     * own, with the condition the search used for them (filters and tenant included). Null when
     * there is nothing to probe: fewer than two words that are not stop words (stop words are
     * ignored by the search already and are never reported). "ignored" is empty when every word
     * matches something, and when none does (then there is nothing to keep).
     *
     * @param list<string>|null $emptyQueries the stop-word tsqueries when the search asked for them already, else null
     * @param list<Condition>   $conditions
     * @param list<string>|null $allowedFields a restricted viewer's fields: a word matches what the search would let it match
     *
     * @return array{
     *     statement: array{label: string, sql: string, params: array<string, scalar|null>},
     *     ignored: list<Term|Phrase|FieldScoped|AnyOf>
     * }|null
     */
    private function probe(IndexDefinition $index, Node $root, bool $fuzzy, ?array $emptyQueries, array $conditions, Thresholds $thresholds, ?array $allowedFields): ?array
    {
        $compiler = new TsQueryCompiler($index);
        $leaves = [];
        $tsqueries = [];
        foreach (Relaxation::positiveLeaves($root) as $leaf) {
            $tsquery = $compiler->compile($leaf);
            if ($tsquery !== null) {
                $leaves[] = $leaf;
                $tsqueries[] = $tsquery;
            }
        }
        if (count($leaves) < 2) {
            return null;
        }
        $empty = $emptyQueries ?? $this->emptyQueries($index, array_values(array_unique($tsqueries)));
        $probed = [];
        foreach ($leaves as $i => $leaf) {
            if (!in_array($tsqueries[$i], $empty, true)) {
                $probed[] = $leaf;
            }
        }
        if (count($probed) < 2) {
            return null;
        }

        $statement = ['label' => self::PROBE_LABEL] + (new SearchSqlBuilder($index, $this->names, [], $allowedFields, true, cappedColumn: $this->cappedColumn($index)))->probe($probed, $fuzzy, $conditions, $thresholds, $empty);
        $compiler = new FuzzyQueryCompiler($index, $thresholds, $this->names, [], $allowedFields, true, cappedColumn: $this->cappedColumn($index));
        $row = $this->run($statement, $fuzzy ? $compiler->lowestSimilarity(new AllOf($probed), $empty) : null)[0] ?? [];
        $ignored = [];
        foreach ($probed as $i => $leaf) {
            if (in_array($row['l' . $i] ?? null, [false, 'f', 0], true)) {
                $ignored[] = $leaf;
            }
        }

        return ['statement' => $statement, 'ignored' => count($ignored) < count($probed) ? $ignored : []];
    }

    /**
     * The stop-word leaf tsqueries a search drops (emptyQueries()), except, for a query of stop words only and when the
     * typo-tolerant branch can run, its positive words: that branch matches them on the normalised text, where the
     * stop words are (FuzzyQueryCompiler::keepingStopWords()). A query with a real word is as it was.
     *
     * @return list<string>
     */
    private function fuzzyStopWords(IndexDefinition $index, FuzzyQueryCompiler $fuzzy, Node $query, ?Node $fuzzyRoot): array
    {
        $empty = $this->emptyQueries($index, $fuzzy->leafQueries($query));

        return $fuzzyRoot === null || $empty === [] ? $empty : $fuzzy->keepingStopWords($fuzzyRoot, $empty);
    }

    /**
     * The leaf tsqueries the index's text configuration reduces to nothing (stop words such as
     * "for"). The strict tsquery drops them inside PostgreSQL; the per-word fuzzy branch must
     * drop them too, or "mouse for gaming" would require a word similar to "for". Asked right
     * before a fuzzy statement is built, in one round trip.
     *
     * @param list<string> $queries
     *
     * @return list<string>
     */
    private function emptyQueries(IndexDefinition $index, array $queries): array
    {
        // Whether a tsquery reduces to nothing depends only on the text search configuration (its dictionaries and stop word
        // lists), which does not change while a process runs: every answer, "empty" and "not empty", is kept per
        // (configuration, tsquery), and PostgreSQL is asked only for the tsqueries it has not answered yet.
        $config = $this->names->regconfig($index->text);
        if (count($this->emptyAnswers[$config] ?? []) > self::EMPTY_CACHE) {
            $this->emptyAnswers[$config] = [];
        }
        $unknown = array_values(array_unique(array_filter($queries, fn(string $query): bool => !isset($this->emptyAnswers[$config][$query]))));
        if ($unknown !== []) {
            $rows = $this->guard('search', fn(): array => $this->connection->fetchAll(
                sprintf(
                    'SELECT t.q FROM unnest(CAST(:queries AS text[])) AS t(q) WHERE numnode(to_tsquery(%s, t.q)) = 0',
                    $config,
                ),
                ['queries' => Sql::arrayLiteral($unknown)],
            ), 'Run "bin/console fuzzphony:doctor" to check the index.');
            $empty = array_flip(array_map(static fn(array $row): string => Coerce::str($row['q']), $rows));
            foreach ($unknown as $query) {
                $this->emptyAnswers[$config][$query] = isset($empty[$query]);
            }
        }

        return array_values(array_filter($queries, fn(string $query): bool => $this->emptyAnswers[$config][$query]));
    }

    /**
     * The documents each typo-tolerant word matches on its own, asked word by word at the word's own similarity:
     * with the session's threshold set for the lowest word of the whole statement, the trigram index would return
     * (and PostgreSQL read) every row that merely starts alike for a word that needs more (see FuzzyQueryCompiler).
     * A word with more matches than the candidate limit is left out of `candidates` and keeps its trigram condition.
     *
     * Each probe applies the search's conditions (tenant and filters): only documents the search could return are probed.
     *
     * @param list<string>    $emptyQueries
     * @param list<Condition> $conditions
     *
     * @return array<string, list<int|string>> by candidateKey()
     */
    private function fuzzyCandidates(FuzzyQueryCompiler $fuzzy, Node $root, array $emptyQueries, Thresholds $thresholds, array $conditions): array
    {
        $cap = $thresholds->candidateLimit;
        $candidates = [];
        foreach ($fuzzy->probes($root, $cap, $emptyQueries, $conditions) as $key => $probe) {
            $ids = $this->probeIds($probe);
            if (count($ids) <= $cap) {
                $candidates[$key] = $ids;
            }
        }

        return $candidates;
    }

    /**
     * @param array{sql: string, params: array<string, scalar|null>, similarity: float} $probe
     *
     * @return list<int|string>
     */
    private function probeIds(array $probe): array
    {
        $ids = array_map(static fn(array $row): int|string => is_int($row['id']) ? $row['id'] : Coerce::str($row['id']), $this->run(['sql' => $probe['sql'], 'params' => $probe['params']], $probe['similarity']));
        sort($ids); // the probe has no order: the ids are bound into statements (explain() shows them), which must not follow the heap

        return $ids;
    }

    /**
     * @param array{sql: string, params: array<string, scalar|null>} $statement
     *
     * @return list<array<string, mixed>>
     */
    private function run(array $statement, ?float $similarityThreshold): array
    {
        return $this->guard('search', fn(): array => $this->fetchWithSetting($statement['sql'], $statement['params'], $similarityThreshold, self::WORD_SIMILARITY), self::SEARCH_HINT);
    }

    /**
     * Runs $work, the statements of one search, in ONE transaction when this engine may own it, instead of one transaction
     * per statement that sets a pg_trgm setting (BEGIN, the setting, the statement, COMMIT: four round trips for one SELECT).
     * Each statement still sets what it needs (settle()), so none sees another's value, and the settings are transaction-local,
     * so COMMIT leaves the session as it was. When the caller has a transaction open, or the connection cannot say, nothing
     * is opened here: each statement does what it always did (its own setting, and the previous value put back).
     *
     * A statement that fails ends the search with its EngineFailure and rolls the transaction back, as it did when the
     * transaction was its own; no statement of a search is allowed to fail and be ignored, so nothing is left to poison.
     * A failing BEGIN or COMMIT is an EngineFailure of the search too.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function inSearchTransaction(\Closure $work): mixed
    {
        if ($this->settings !== null || !$this->connection instanceof TransactionAware || $this->connection->inTransaction()) {
            return $work();
        }
        $state = new \stdClass();
        $state->entered = false;
        $state->thrown = null;
        try {
            return $this->connection->transactional(function () use ($work, $state): mixed {
                $state->entered = true;
                $this->settings = [];
                try {
                    return $work();
                } catch (\Throwable $e) {
                    $state->thrown = $e;

                    throw $e;
                } finally {
                    $this->settings = null;
                }
            });
        } catch (FuzzphonyException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($state->entered === true && $e === $state->thrown) {
                throw $e; // not the database's: it passes as it would without a transaction
            }
            // BEGIN, COMMIT or ROLLBACK failed
            $this->metrics->increment('fuzzphony.search.errors');

            throw EngineFailure::wrap('search', $e, self::SEARCH_HINT);
        }
    }

    /**
     * One statement, with the pg_trgm setting $name at $value (null: it needs none).
     *
     * Inside the search transaction of this engine the setting is made there; otherwise a statement with a setting is
     * wrapped in a transaction of its own (and joins the caller's, putting the previous value back).
     *
     * @param array<string, scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    private function fetchWithSetting(string $sql, array $params, ?float $value, string $name): array
    {
        if ($this->settings !== null) {
            $this->settle($sql, $value, $name);

            return $this->connection->fetchAll($sql, $params);
        }
        if ($value === null) {
            // nothing to set, so nothing to scope to a transaction: a single SELECT needs no BEGIN and COMMIT (two round trips)
            return $this->connection->fetchAll($sql, $params);
        }

        // Computed before transactional() opens (or joins) a transaction, so it reflects whether
        // the *caller* already had one open, not the one this call is about to start itself.
        $restore = !($this->connection instanceof TransactionAware) || $this->connection->inTransaction();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->transactional(
            static fn(Connection $c) => self::withSimilarityThreshold($c, $value, $restore, static fn() => $c->fetchAll($sql, $params), $name),
        );

        return $rows;
    }

    /**
     * Inside the search transaction: makes the setting what the next statement needs, and nothing more.
     *
     * A value that is already in force is not set again (a transaction keeps it until it ends). The first time a setting is
     * set, the value the session had is read in the same round trip, as withSimilarityThreshold() reads it. A statement that
     * sets nothing must see the session's own value, as it did outside a transaction: the one that compares words with `<%`
     * (the only operator that reads word_similarity_threshold) gets it back when this transaction has changed it; the others
     * (the strict full-text statements, the facets over strict candidates) do not read the setting and cost no round trip.
     */
    private function settle(string $sql, ?float $value, string $name): void
    {
        $setting = $this->settings[$name] ?? null;
        if ($value === null) {
            if ($setting !== null && $setting['current'] !== $setting['original'] && $name === self::WORD_SIMILARITY && str_contains($sql, '<%')) {
                $this->connection->fetchValue(sprintf("SELECT set_config('%s', :v, true)", $name), ['v' => $setting['original']]);
                $this->settings[$name]['current'] = $setting['original'];
            }

            return;
        }
        $text = (string) $value;
        if ($setting === null) {
            $previous = $this->connection->fetchValue(
                sprintf("WITH old AS MATERIALIZED (SELECT current_setting('%1\$s', true) AS v) SELECT v, set_config('%1\$s', :t, true) FROM old", $name),
                ['t' => $text],
            );
            $this->settings[$name] = ['original' => is_string($previous) ? $previous : null, 'current' => $text];
        } elseif ($setting['current'] !== $text) {
            $this->connection->fetchValue(sprintf("SELECT set_config('%s', :v, true)", $name), ['v' => $text]);
            $this->settings[$name]['current'] = $text;
        }
    }

    /**
     * Runs $work with pg_trgm.word_similarity_threshold set, which lets "<%" use the trigram
     * index, and puts the previous value back afterwards. The setting is transaction-local, but
     * when the caller already has a transaction open (or a savepoint is released) it would
     * otherwise stay on until the caller commits. A search leaves no session state behind.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private static function withSimilarityThreshold(Connection $c, ?float $similarityThreshold, bool $restore, \Closure $work, string $name = self::WORD_SIMILARITY): mixed
    {
        if ($similarityThreshold === null) {
            return $work();
        }
        // the previous value is read before the new one is set (the CTE is evaluated first);
        // NULL (the extension is not loaded yet) is restored as the default
        $previous = $c->fetchValue(
            sprintf("WITH old AS MATERIALIZED (SELECT current_setting('%1\$s', true) AS v) SELECT v, set_config('%1\$s', :t, true) FROM old", $name),
            ['t' => (string) $similarityThreshold],
        );
        $result = $work();
        if ($restore) {
            $c->fetchValue(sprintf("SELECT set_config('%s', :v, true)", $name), ['v' => is_string($previous) ? $previous : null]);
        }

        return $result;
    }

    /**
     * The ids of the candidates a ranked statement returned (its `cand_ids`, when it was asked for them), else null.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<int|string>|null
     */
    private static function candidateIds(array $rows): ?array
    {
        if ($rows === [] || !array_key_exists('cand_ids', $rows[0])) {
            return null;
        }
        $json = $rows[0]['cand_ids'];
        if ($json === null) {
            return []; // no candidate passed the minimum score
        }
        $ids = json_decode(Coerce::str($json), true, 4, JSON_THROW_ON_ERROR);
        assert(is_array($ids));

        return array_values(array_map(static fn(mixed $id): int|string => is_int($id) ? $id : Coerce::str($id), $ids));
    }

    /** @param list<array<string, mixed>> $rows */
    private static function total(array $rows): int
    {
        return $rows === [] ? 0 : Coerce::int($rows[0]['total']);
    }

    /**
     * Rows of the page; the meta row of an empty page (id NULL) is dropped.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function hitsOnly(array $rows): array
    {
        return array_values(array_filter($rows, static fn(array $row): bool => $row['id'] !== null));
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function guard(string $name, callable $operation, string $hint): mixed
    {
        $started = hrtime(true);
        try {
            $result = $operation();
            $this->metrics->observe('fuzzphony.' . $name . '.duration_ms', round((hrtime(true) - $started) / 1e6, 3));

            return $result;
        } catch (FuzzphonyException $e) {
            $this->metrics->increment('fuzzphony.' . $name . '.errors');
            throw $e;
        } catch (\Throwable $e) {
            $this->metrics->increment('fuzzphony.' . $name . '.errors');
            throw EngineFailure::wrap($name, $e, $hint);
        }
    }
}
