<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Database\TransactionAware;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Capabilities;
use Fuzzphony\Core\Engine\Capability;
use Fuzzphony\Core\Engine\Engine;
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
use Fuzzphony\Engine\Postgres\Sql\TsQueryCompiler;

final class PostgresEngine implements Engine, Vocabulary
{
    private const PROBE_LABEL = 'relaxation probe';
    private const SUGGEST_LABEL = 'did you mean';
    private const string REBUILD_HINT = 'Run "fuzzphony:schema --apply" and "fuzzphony:doctor".';

    private readonly Names $names;
    private readonly PostgresSchemaGenerator $schema;
    private readonly ShadowRebuild $rebuild;
    /** Most stems kept per text configuration; the cache starts over beyond it. */
    private const int STEM_CACHE = 5_000;
    /** @var array<string, SynonymExpander> an expander per index definition, built once */
    private array $expanders = [];
    /** @var array<string, array<string, string>> text configuration => lowercase word => stem */
    private array $stems = [];

    /**
     * @param string $extensionSchema schema of the pg_trgm and unaccent extensions
     * @param string $schema          schema of Fuzzphony's own tables, functions and text search configurations
     */
    public function __construct(
        private readonly Connection $connection,
        string $extensionSchema = 'public',
        string $schema = 'public',
        private readonly MetricsCollector $metrics = new NullMetricsCollector(),
    ) {
        $this->names = new Names($extensionSchema, $schema);
        $this->schema = new PostgresSchemaGenerator($this->names);
        $this->rebuild = new ShadowRebuild($connection, $this->schema);
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
            if ($statement['label'] !== self::PROBE_LABEL && $statement['label'] !== self::SUGGEST_LABEL) {
                $last = $statement;
            }
        }
        if ($last !== null) {
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

    public function inspect(IndexDefinition $index, InspectOptions $options = new InspectOptions()): InspectionReport
    {
        return $this->guard(
            'inspection',
            fn(): InspectionReport => (new PostgresInspector($this->connection, $this->schema))->inspect($index, $options),
            'Check that this connection can read the catalog and the source.',
        );
    }

    /**
     * @return array{
     *     result: SearchResult,
     *     statements: list<array{label: string, sql: string, params: array<string, scalar|null>}>,
     *     threshold: float|null
     * }
     */
    private function execute(IndexDefinition $index, SearchQuery $query): array
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
        $profile = $query->rankingOverrides === [] ? $index->profile($query->profile) : $index->profile($query->profile)->with($query->rankingOverrides);
        (new FilterCompiler($index))->validate(...$conditions);

        $parsed = (new QueryParser($thresholds->maxQueryLength, $thresholds->maxTerms))->parse($query->text);
        $warnings = $parsed->warnings;
        $root = $parsed->root;
        if ($root === null && trim($query->text) !== '') {
            // Text was typed but nothing searchable survived parsing ("***"): browsing would list the whole index.
            $warnings[] = 'The search has no word to look for; use letters or digits.';
            $empty = SearchResult::empty($query->limit, $query->offset, $warnings, round((hrtime(true) - $started) / 1e6, 3));

            return ['result' => $empty, 'statements' => [], 'threshold' => null];
        }
        if ($root !== null && !NodeInspector::hasPositive($root)) {
            // "-cable" alone would mean "everything except ..." which is rarely intended and never cheap.
            $warnings[] = 'The search only excluded words; add at least one word to look for.';
            $empty = SearchResult::empty($query->limit, $query->offset, $warnings, round((hrtime(true) - $started) / 1e6, 3));

            return ['result' => $empty, 'statements' => [], 'threshold' => null];
        }

        $typedRoot = $root;
        if ($root !== null && !$index->synonyms->isEmpty()) {
            $root = $this->expandSynonyms($index, $root, $thresholds, $warnings);
        }
        $expandedRoot = $root;

        $run = $this->pipeline($index, $root, $conditions, $profile, $thresholds, $query, '');
        $statements = $run['statements'];
        array_push($warnings, ...$run['warnings']);
        // setting of the last search statement, which explain() runs again
        $threshold = $run['threshold'];

        // Empty-result relaxation: drop the words that match nothing on their own, search once more.
        if ($root !== null && $thresholds->relaxWhenEmpty && self::total($run['rows']) === 0) {
            $probe = $this->probe($index, $root, $run['fuzzy'], $run['emptyQueries'], $conditions, $thresholds);
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
        $capped = $rows !== [] && (Coerce::int($rows[0]['fts_n']) >= $thresholds->candidateLimit || Coerce::int($rows[0]['fuzzy_n']) >= $thresholds->candidateLimit);
        if ($run['browse']) {
            $capped = $total >= $thresholds->candidateLimit;
        }
        $rows = self::hitsOnly($rows);
        $suggestion = null;
        $didYouMean = $thresholds->didYouMean && $typedRoot !== null && $expandedRoot !== null && !$run['browse'] && $index->hasFuzzy() && ($total < $thresholds->fallbackBelow || $run['usedFuzzy'])
            ? $this->suggest($index, $typedRoot, $expandedRoot, $thresholds, $suggestion)
            : null;
        if ($suggestion !== null) {
            $statements[] = $suggestion;
        }

        $highlights = [];
        if ($query->highlight !== [] && $tsquery !== null && $rows !== []) {
            $headline = $tsquery;
            $highlights = $this->guard('highlighting', fn(): array => (new Highlighter($this->connection, $this->names))->highlight(
                $index,
                $query->highlight,
                $headline,
                array_map(static fn(array $r): int|string => $index->idType->cast(Coerce::str($r['id'])), $rows),
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
        );
        $this->metrics->observe('fuzzphony.search.took_ms', $result->tookMs, ['index' => $index->name, 'query' => $query->text]);
        if (array_any($statements, static fn(array $s): bool => str_ends_with($s['label'], 'fallback: full-text + fuzzy'))) {
            $this->metrics->increment('fuzzphony.search.fallback', ['index' => $index->name, 'query' => $query->text]);
        }

        return ['result' => $result, 'statements' => $statements, 'threshold' => $threshold];
    }

    /**
     * The query with the words it probably meant, for a search that found few hits: a whole word the
     * vocabulary does not have (and that is not a stop word, nor one a synonym expanded) is replaced
     * by the vocabulary word nearest to it. The trigram index picks the ten closest candidates, then
     * the edit distance decides (a trigram ranking alone suggests `most` for `mose`), then how many
     * documents have the word; nothing farther than a third of the word's length away is suggested.
     * Null when no word has a better one. $statement is set to the statement that looked the words up, for explain().
     *
     * @param array{label: string, sql: string, params: array<string, scalar|null>}|null $statement
     */
    private function suggest(IndexDefinition $index, Node $typed, Node $expanded, Thresholds $thresholds, ?array &$statement = null): ?string
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
        // a table the schema has not created yet, or one this role may not read (an upgrade before "fuzzphony:schema --apply"
        // and the GRANT), is no reason to fail a search, and a failed statement would abort a transaction the caller may have open
        if ($this->connection->fetchValue("SELECT to_regclass(:a) IS NOT NULL AND has_table_privilege(to_regclass(:b), 'SELECT')", ['a' => $vocabulary, 'b' => $vocabulary]) !== true) {
            return null;
        }
        $extension = $this->names->extension();
        $restore = !($this->connection instanceof TransactionAware) || $this->connection->inTransaction();
        // known: a stop word, a word of the vocabulary, or any word the index finds (a word of a field that is not typo-tolerant,
        // an inflection, a word added since the last full reindex): the search above found what it could
        $sql = sprintf(
            "SELECT q.w, q.n, (to_tsvector(%2\$s, q.w) = ''::tsvector OR EXISTS (SELECT 1 FROM %1\$s AS v WHERE v.word = q.n) OR EXISTS (SELECT 1 FROM %5\$s AS s WHERE s.tsv @@ plainto_tsquery(%2\$s, q.w))) AS known, c.word, c.freq FROM (SELECT w, %3\$s(w) AS n FROM unnest(string_to_array(:words, chr(31))) AS w) AS q LEFT JOIN LATERAL (SELECT v.word, v.freq FROM %1\$s AS v WHERE v.word OPERATOR(%4\$s.%%) q.n ORDER BY %4\$s.similarity(v.word, q.n) DESC, v.freq DESC LIMIT 10) AS c ON true",
            $vocabulary,
            $this->names->regconfig($index->text),
            $this->names->normFunction(),
            $extension,
            $this->names->sidecar($index),
        );
        $parameters = ['words' => implode(chr(31), $words)];
        $statement = ['label' => self::SUGGEST_LABEL, 'sql' => $sql, 'params' => $parameters];
        // the candidates are the words with a trigram similarity of 0.3 or more, whatever the session has set
        /** @var \Closure(): list<array<string, mixed>> $lookup */
        $lookup = fn() => $this->connection->transactional(
            static fn(Connection $c) => self::withSimilarityThreshold($c, 0.3, $restore, static fn() => $c->fetchAll($sql, $parameters), 'pg_trgm.similarity_threshold'),
        );
        $rows = $this->guard('did_you_mean', $lookup, 'Run "bin/console fuzzphony:schema --apply", then "bin/console fuzzphony:reindex --vocabulary".');

        /** @var array<string, array{int, int, string}> $best typed word => [distance, -documents, vocabulary word] */
        $best = [];
        $known = [];
        foreach ($rows as $row) {
            $word = Coerce::str($row['w']);
            if (in_array($row['known'], [true, 't', 'true', 1, '1'], true)) {
                $known[$word] = true;

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
            $rank = [$distance, -Coerce::int($row['freq']), $candidate];
            if (!isset($best[$word]) || $rank < $best[$word]) {
                $best[$word] = $rank;
            }
        }
        $replace = array_map(static fn(array $rank): string => $rank[2], array_diff_key($best, $known));
        if ($replace === []) {
            return null;
        }

        return QueryRenderer::render($typed, $replace);
    }

    /** The edit distance of two words by their characters (PHP's levenshtein() counts bytes). */
    private static function distance(string $a, string $b): int
    {
        $map = [];
        $encode = static function (string $word) use (&$map): string {
            $out = '';
            foreach (mb_str_split($word) as $char) {
                $map[$char] ??= chr(1 + count($map) % 254);
                $out .= $map[$char];
            }

            return $out;
        };

        return levenshtein($encode($a), $encode($b));
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
        $key = $index->name . '|' . $config . '|' . md5(serialize($index->synonyms->toEntries()));
        if (count($this->stems[$config] ?? []) > self::STEM_CACHE) {
            $this->stems[$config] = [];
        }
        $memberWords = isset($this->expanders[$key]) ? [] : SynonymExpander::wordsOf($index->synonyms);
        $missing = array_values(array_unique(array_filter([...$memberWords, ...$queryWords], fn(string $word): bool => !isset($this->stems[$config][$word]))));
        if ($missing !== []) {
            $rows = $this->guard('synonyms', fn(): array => $this->connection->fetchAll(
                sprintf("SELECT w, CASE WHEN w ~ '^[[:alnum:]]+\$' THEN coalesce(nullif(array_to_string(tsvector_to_array(to_tsvector(%s, w)), ' '), ''), w) ELSE w END AS s FROM unnest(string_to_array(:words, chr(31))) AS w", $config),
                ['words' => implode(chr(31), $missing)],
            ), 'Run "bin/console fuzzphony:doctor" to check the index.');
            foreach ($rows as $row) {
                $this->stems[$config][Coerce::str($row['w'])] = Coerce::str($row['s']);
            }
        }
        $this->expanders[$key] ??= new SynonymExpander($index->synonyms, array_intersect_key($this->stems[$config] ?? [], array_flip($memberWords)));
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
     *     warnings: list<string>
     * }
     */
    private function pipeline(IndexDefinition $index, ?Node $root, array $conditions, RankingProfile $profile, Thresholds $thresholds, SearchQuery $query, string $labelPrefix): array
    {
        $warnings = [];
        $tsquery = null;
        $scopedRoot = null;
        if ($root !== null) {
            $compiler = new TsQueryCompiler($index);
            $tsquery = $compiler->compile($root);
            $warnings = $compiler->warnings();
            // a word scoped to a field the index has is rechecked against that field's own column
            $scopedRoot = $compiler->hasFieldScope($root) ? $root : null;
        }
        $plain = implode(' ', TsQueryCompiler::lexemes(implode(' ', NodeInspector::positiveWords($root))));

        // Typo tolerance is per word (FuzzyQueryCompiler); it needs at least one positive word long enough for it.
        $fuzzy = new FuzzyQueryCompiler($index, $thresholds, $this->names);
        $fuzzyRoot = $root !== null
            && $index->hasFuzzy()
            && $profile->fuzzy > 0.0
            && $thresholds->fuzzyMode !== FuzzyMode::Never
            && $fuzzy->hasFuzzyLeaf($root) ? $root : null;

        $builder = new SearchSqlBuilder($index, $this->names);
        $statements = [];
        $usedFuzzy = false;
        $threshold = null;
        $browse = false;
        // the recheck drops stop words as the strict tsquery does, so it needs them up front
        $emptyQueries = $scopedRoot === null ? null : $this->emptyQueries($index, $fuzzy->leafQueries($scopedRoot));

        if ($tsquery === null && $plain === '') {
            $statement = ['label' => $labelPrefix . 'browse'] + $builder->browse($conditions, $profile, $thresholds, $query->limit, $query->offset);
            $rows = $this->run($statement, null);
            $statements[] = $statement;
            $browse = true;
        } else {
            $alwaysFuzzy = $fuzzyRoot !== null
                && ($thresholds->fuzzyMode === FuzzyMode::Always || $tsquery === null)
                && $fuzzy->hasFuzzyLeaf($fuzzyRoot, $emptyQueries ??= $this->emptyQueries($index, $fuzzy->leafQueries($fuzzyRoot)));
            $statement = ['label' => $labelPrefix . ($alwaysFuzzy ? 'full-text + fuzzy' : 'full-text')]
                + $builder->ranked($tsquery, $plain, $alwaysFuzzy ? $fuzzyRoot : null, $conditions, $profile, $thresholds, $query->limit, $query->offset, $emptyQueries ?? [], $scopedRoot);
            $threshold = $alwaysFuzzy && $fuzzyRoot !== null ? $fuzzy->lowestSimilarity($fuzzyRoot, $emptyQueries ?? []) : null;
            $rows = $this->run($statement, $threshold);
            $statements[] = $statement;
            $usedFuzzy = $alwaysFuzzy;

            if (
                !$alwaysFuzzy
                && $fuzzyRoot !== null
                && $thresholds->fuzzyMode === FuzzyMode::Fallback
                && self::total($rows) < $thresholds->fallbackBelow
                && $fuzzy->hasFuzzyLeaf($fuzzyRoot, $emptyQueries ??= $this->emptyQueries($index, $fuzzy->leafQueries($fuzzyRoot)))
            ) {
                $statement = ['label' => $labelPrefix . 'fallback: full-text + fuzzy']
                    + $builder->ranked($tsquery, $plain, $fuzzyRoot, $conditions, $profile, $thresholds, $query->limit, $query->offset, $emptyQueries, $scopedRoot);
                $threshold = $fuzzy->lowestSimilarity($fuzzyRoot, $emptyQueries);
                $rows = $this->run($statement, $threshold);
                $statements[] = $statement;
                $usedFuzzy = true;
            }
        }

        return [
            'rows' => $rows,
            'statements' => $statements,
            'usedFuzzy' => $usedFuzzy,
            'threshold' => $threshold,
            'tsquery' => $tsquery,
            'fuzzy' => $fuzzyRoot !== null,
            'emptyQueries' => $emptyQueries,
            'browse' => $browse,
            'warnings' => $warnings,
        ];
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
     *
     * @return array{
     *     statement: array{label: string, sql: string, params: array<string, scalar|null>},
     *     ignored: list<Term|Phrase|FieldScoped|AnyOf>
     * }|null
     */
    private function probe(IndexDefinition $index, Node $root, bool $fuzzy, ?array $emptyQueries, array $conditions, Thresholds $thresholds): ?array
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

        $statement = ['label' => self::PROBE_LABEL] + (new SearchSqlBuilder($index, $this->names))->probe($probed, $fuzzy, $conditions, $thresholds, $empty);
        $compiler = new FuzzyQueryCompiler($index, $thresholds, $this->names);
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
        $rows = $this->guard('search', fn(): array => $this->connection->fetchAll(
            sprintf(
                'SELECT t.q FROM unnest(CAST(:queries AS text[])) AS t(q) WHERE numnode(to_tsquery(%s, t.q)) = 0',
                $this->names->regconfig($index->text),
            ),
            ['queries' => Sql::arrayLiteral($queries)],
        ), 'Run "bin/console fuzzphony:doctor" to check the index.');

        return array_map(static fn(array $row): string => Coerce::str($row['q']), $rows);
    }

    /**
     * @param array{sql: string, params: array<string, scalar|null>} $statement
     *
     * @return list<array<string, mixed>>
     */
    private function run(array $statement, ?float $similarityThreshold): array
    {
        // Computed before transactional() opens (or joins) a transaction, so it reflects whether
        // the *caller* already had one open — not the one this call is about to start itself.
        $restore = !($this->connection instanceof TransactionAware) || $this->connection->inTransaction();

        /** @var \Closure(): list<array<string, mixed>> $search */
        $search = fn() => $this->connection->transactional(
            static fn(Connection $c) => self::withSimilarityThreshold(
                $c,
                $similarityThreshold,
                $restore,
                static fn() => $c->fetchAll($statement['sql'], $statement['params']),
            ),
        );

        return $this->guard('search', $search, 'Run "bin/console fuzzphony:doctor" to check the index.');
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
    private static function withSimilarityThreshold(Connection $c, ?float $similarityThreshold, bool $restore, \Closure $work, string $name = 'pg_trgm.word_similarity_threshold'): mixed
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
