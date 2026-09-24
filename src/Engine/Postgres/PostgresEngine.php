<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Capabilities;
use Fuzzphony\Core\Engine\Capability;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Inspection\InspectionReport;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Query\Ast\NodeInspector;
use Fuzzphony\Core\Query\Filter\Condition;
use Fuzzphony\Core\Query\Filter\Operator;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Ranking\FuzzyMode;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\ScoreBreakdown;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Inspection\PostgresInspector;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Sql\DocumentSql;
use Fuzzphony\Engine\Postgres\Sql\FilterCompiler;
use Fuzzphony\Engine\Postgres\Sql\SearchSqlBuilder;
use Fuzzphony\Engine\Postgres\Sql\Sql;
use Fuzzphony\Engine\Postgres\Sql\TsQueryCompiler;

final class PostgresEngine implements Engine
{
    private readonly PostgresSchemaGenerator $schema;

    public function __construct(
        private readonly Connection $connection,
        string $extensionSchema = 'public',
    ) {
        $this->schema = new PostgresSchemaGenerator($extensionSchema);
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
        $last = $run['statements'] === [] ? null : $run['statements'][array_key_last($run['statements'])];
        if ($last !== null) {
            $plan = $this->connection->transactional(function (Connection $c) use ($last, $analyze, $run): array {
                if ($run['threshold'] !== null) {
                    $c->fetchValue("SELECT set_config('pg_trgm.word_similarity_threshold', :t, true)", ['t' => (string) $run['threshold']]);
                }
                $rows = $c->fetchAll(($analyze ? 'EXPLAIN (ANALYZE, BUFFERS) ' : 'EXPLAIN ') . $last['sql'], $last['params']);

                return array_map(static fn(array $row): string => Coerce::str(reset($row)), $rows);
            });
        }

        return new Explanation($run['result']->interpretedAs ?? '', $run['statements'], $plan, $run['result']);
    }

    public function refresh(IndexDefinition $index, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->guard('refresh', fn(): int => Coerce::int($this->connection->fetchValue(
            sprintf('SELECT %s(CAST(:ids AS %s[]))', Sql::ident($this->schema->refreshFunctionName($index)), $index->idType->sqlType()),
            ['ids' => Sql::arrayLiteral(array_values($ids))],
        )), 'Run "fuzzphony:schema --apply" to create the refresh function, then "fuzzphony:doctor".');
    }

    public function sourceIds(IndexDefinition $index, int|string|null $after, int $limit): array
    {
        $params = ['limit' => $limit];
        $where = '';
        if ($after !== null) {
            $where = sprintf('WHERE doc.fz_id > CAST(:after AS %s)', $index->idType->sqlType());
            $params['after'] = (string) $after;
        }
        $rows = $this->connection->fetchAll(sprintf(
            'SELECT doc.fz_id::text AS id FROM (%s) AS doc %s ORDER BY doc.fz_id LIMIT :limit',
            DocumentSql::select($index),
            $where,
        ), $params);

        return array_map(static fn(array $row): int|string => $index->idType->cast(Coerce::str($row['id'])), $rows);
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
                        WHERE index_name = :index
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
            PostgresSchemaGenerator::QUEUE_TABLE,
            Sql::ident($this->schema->refreshFunctionName($index)),
            $index->idType->sqlType(),
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
        return Coerce::int($this->connection->fetchValue(
            sprintf('SELECT count(*) FROM %s WHERE index_name = :index', PostgresSchemaGenerator::QUEUE_TABLE),
            ['index' => $index->name],
        ));
    }

    public function inspect(IndexDefinition $index, InspectOptions $options = new InspectOptions()): InspectionReport
    {
        return (new PostgresInspector($this->connection, $this->schema))->inspect($index, $options);
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
        $conditions = $index->tenant !== null
            ? [new Condition($index->tenant, Operator::Eq, $query->tenant), ...$query->conditions]
            : $query->conditions;

        $thresholds = $index->thresholds->with($query->thresholdOverrides);
        $profile = $query->rankingOverrides === [] ? $index->profile($query->profile) : $index->profile($query->profile)->with($query->rankingOverrides);
        (new FilterCompiler($index))->validate(...$conditions);

        $parsed = (new QueryParser($thresholds->maxQueryLength, $thresholds->maxTerms))->parse($query->text);
        $warnings = $parsed->warnings;
        $root = $parsed->root;
        if ($root !== null && !NodeInspector::hasPositive($root)) {
            // "-cable" alone would mean "everything except ..." which is rarely intended and never cheap.
            $warnings[] = 'The search only excluded words; add at least one word to look for.';
            $empty = SearchResult::empty($query->limit, $query->offset, $warnings, round((hrtime(true) - $started) / 1e6, 3));

            return ['result' => $empty, 'statements' => [], 'threshold' => null];
        }

        $tsquery = null;
        $exclusions = null;
        if ($root !== null) {
            $compiler = new TsQueryCompiler($index);
            $tsquery = $compiler->compile($root);
            array_push($warnings, ...$compiler->warnings());
            $excluded = array_filter(array_map($compiler->compile(...), NodeInspector::topLevelExclusions($root)), static fn(?string $e): bool => $e !== null);
            $exclusions = $excluded === [] ? null : implode(' | ', $excluded);
        }
        $plain = implode(' ', TsQueryCompiler::lexemes(implode(' ', NodeInspector::positiveWords($root))));

        $fuzzyEligible = $index->hasFuzzy()
            && $profile->fuzzy > 0.0
            && $thresholds->fuzzyMode !== FuzzyMode::Never
            && mb_strlen(str_replace(' ', '', $plain)) >= $thresholds->fuzzyMinLength;

        $builder = new SearchSqlBuilder($index);
        $statements = [];
        $usedFuzzy = false;
        $threshold = null;

        if ($tsquery === null && $plain === '') {
            $statement = ['label' => 'browse'] + $builder->browse($conditions, $profile, $thresholds, $query->limit, $query->offset);
            $rows = $this->run($statement, null);
            $statements[] = $statement;
        } else {
            $alwaysFuzzy = $fuzzyEligible && ($thresholds->fuzzyMode === FuzzyMode::Always || $tsquery === null);
            $statement = ['label' => $alwaysFuzzy ? 'full-text + fuzzy' : 'full-text']
                + $builder->ranked($tsquery, $plain, $alwaysFuzzy, $conditions, $profile, $thresholds, $query->limit, $query->offset, $exclusions);
            $threshold = $alwaysFuzzy ? $thresholds->fuzzySimilarity : null;
            $rows = $this->run($statement, $threshold);
            $statements[] = $statement;
            $usedFuzzy = $alwaysFuzzy;

            if (!$alwaysFuzzy && $fuzzyEligible && $thresholds->fuzzyMode === FuzzyMode::Fallback && self::total($rows) < $thresholds->fallbackBelow) {
                $statement = ['label' => 'fallback: full-text + fuzzy']
                    + $builder->ranked($tsquery, $plain, true, $conditions, $profile, $thresholds, $query->limit, $query->offset, $exclusions);
                $threshold = $thresholds->fuzzySimilarity;
                $rows = $this->run($statement, $threshold);
                $statements[] = $statement;
                $usedFuzzy = true;
            }
        }

        $total = self::total($rows);
        $capped = $rows !== [] && (Coerce::int($rows[0]['fts_n']) >= $thresholds->candidateLimit || Coerce::int($rows[0]['fuzzy_n']) >= $thresholds->candidateLimit);
        if ($statements[0]['label'] === 'browse') {
            $capped = $total >= $thresholds->candidateLimit;
        }
        $rows = self::hitsOnly($rows);

        $highlights = [];
        if ($query->highlight !== [] && $tsquery !== null && $rows !== []) {
            $highlights = (new Highlighter($this->connection))->highlight(
                $index,
                $query->highlight,
                $tsquery,
                array_map(static fn(array $r): int|string => $index->idType->cast(Coerce::str($r['id'])), $rows),
            );
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
            usedFuzzy: $usedFuzzy,
            warnings: array_values(array_unique($warnings)),
            limit: $query->limit,
            offset: $query->offset,
            interpretedAs: $root !== null ? (string) $root : null,
        );

        return ['result' => $result, 'statements' => $statements, 'threshold' => $threshold];
    }

    /**
     * @param array{sql: string, params: array<string, scalar|null>} $statement
     *
     * @return list<array<string, mixed>>
     */
    private function run(array $statement, ?float $similarityThreshold): array
    {
        return $this->guard('search', fn(): array => $this->connection->transactional(
            static function (Connection $c) use ($statement, $similarityThreshold): array {
                if ($similarityThreshold !== null) {
                    // SET LOCAL semantics: only for this transaction, lets "<%" use the trigram index
                    $c->fetchValue("SELECT set_config('pg_trgm.word_similarity_threshold', :t, true)", ['t' => (string) $similarityThreshold]);
                }

                return $c->fetchAll($statement['sql'], $statement['params']);
            },
        ), 'Run "bin/console fuzzphony:doctor" to check the index.');
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
        try {
            return $operation();
        } catch (FuzzphonyException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw EngineFailure::wrap($name, $e, $hint);
        }
    }
}
