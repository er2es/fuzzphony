<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Sql;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Filter\Condition;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;

/**
 * @internal Builds the ranked search statement:
 *
 *   q      -> the compiled query (bound parameters, evaluated once)
 *   fts    -> full-text candidates via the GIN(tsv) index, capped by candidateLimit
 *   fuzzy  -> per-term candidates: each word via GIN(tsv) OR GIN(fz gin_trgm_ops), capped by candidateLimit
 *   cand   -> union of both branches, one row per document
 *   scored -> relevance + bonuses per candidate
 *   page   -> minScore cut, ordering, pagination
 *   meta   -> one row with totals, LEFT JOINed to the page (totals survive empty pages)
 *
 * Only user-derived values are bound; weights and limits come from validated configuration
 * and are inlined so that EXPLAIN output is readable.
 */
final class SearchSqlBuilder
{
    public function __construct(private readonly IndexDefinition $index) {}

    /**
     * @param string          $plain        positive words, for the exact / prefix bonuses (q.norm)
     * @param Node|null       $fuzzyRoot    the parsed query when the fuzzy branch runs, else null
     * @param list<Condition> $conditions
     * @param list<string>    $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
     *
     * @return array{sql: string, params: array<string, scalar|null>}
     */
    public function ranked(?string $tsquery, string $plain, ?Node $fuzzyRoot, array $conditions, RankingProfile $profile, Thresholds $thresholds, int $limit, int $offset, array $emptyQueries = []): array
    {
        $params = new ParameterBag();
        $filters = new FilterCompiler($this->index);
        $table = Sql::ident($this->index->sidecarTable());
        $config = Sql::string($this->index->text->configName()) . '::regconfig';
        $candidates = $thresholds->candidateLimit;

        $q = [];
        if ($tsquery !== null) {
            $q[] = sprintf('to_tsquery(%s, %s) AS tsq', $config, $params->add($tsquery));
        }
        $q[] = $plain !== ''
            ? sprintf('%s(%s) AS norm', PostgresSchemaGenerator::NORM_FUNCTION, $params->add($plain))
            : "''::text AS norm";
        // Per-term fuzzy branch: every word is satisfied exactly or fuzzily, through the query's
        // own AND / OR / NOT. Its per-word values are extra q columns.
        $fuzzy = $fuzzyRoot === null ? null : (new FuzzyQueryCompiler($this->index, $thresholds))->compile($fuzzyRoot, $params, $emptyQueries);
        if ($fuzzy !== null) {
            array_push($q, ...$fuzzy->columns);
        }
        // MATERIALIZED: one row computed once, and the planner must not see the per-word values (see FuzzyQueryCompiler)
        $ctes = ['q AS MATERIALIZED (SELECT ' . implode(', ', $q) . ')'];

        $branches = [];
        if ($tsquery !== null) {
            $ctes[] = sprintf(
                "fts AS (\n    SELECT s.id, ts_rank_cd('%s'::real[], s.tsv, q.tsq, 32)::double precision AS r_text\n    FROM %s AS s CROSS JOIN q\n    WHERE s.tsv @@ q.tsq AND %s\n    LIMIT %d\n)",
                $profile->tsRankWeights(),
                $table,
                $filters->compile($conditions, $params),
                $candidates,
            );
            $branches[] = 'SELECT id, r_text, 0::double precision AS r_fuzzy FROM fts';
        }
        if ($fuzzy !== null) {
            $ctes[] = sprintf(
                "fuzzy AS (\n    SELECT s.id, (%s)::double precision AS r_fuzzy\n    FROM %s AS s CROSS JOIN q\n    WHERE %s AND %s\n    LIMIT %d\n)",
                $fuzzy->score,
                $table,
                $fuzzy->predicate,
                $filters->compile($conditions, $params),
                $candidates,
            );
            $branches[] = 'SELECT id, 0::double precision AS r_text, r_fuzzy FROM fuzzy';
        }
        if ($branches === []) {
            throw new \LogicException('A ranked search needs a full-text query or a fuzzy branch.');
        }
        $ctes[] = sprintf(
            "cand AS (\n    SELECT id, max(r_text) AS r_text, max(r_fuzzy) AS r_fuzzy\n    FROM (%s) AS u\n    GROUP BY id\n)",
            implode(' UNION ALL ', $branches),
        );
        $ctes[] = sprintf(
            "scored AS (\n    SELECT c.id, c.r_text, c.r_fuzzy,\n        %s * c.r_text + %s * c.r_fuzzy AS relevance,\n        CASE WHEN q.norm <> '' AND s.exact = q.norm THEN %s ELSE 0 END::double precision AS exact_bonus,\n        CASE WHEN q.norm <> '' AND s.exact <> q.norm AND left(s.exact, length(q.norm)) = q.norm THEN %s ELSE 0 END::double precision AS prefix_bonus,\n        %s AS boost_bonus,\n        %s AS recency_bonus\n    FROM cand AS c JOIN %s AS s ON s.id = c.id CROSS JOIN q\n)",
            Sql::float($profile->text),
            Sql::float($profile->fuzzy),
            Sql::float($profile->exactBonus),
            Sql::float($profile->prefixBonus),
            $this->boostExpression($profile),
            $this->recencyExpression($profile),
            $table,
        );

        $counts = [];
        $counts[] = $tsquery !== null ? '(SELECT count(*) FROM fts) AS fts_n' : '0 AS fts_n';
        $counts[] = $fuzzy !== null ? '(SELECT count(*) FROM fuzzy) AS fuzzy_n' : '0 AS fuzzy_n';
        $minScore = Sql::float($thresholds->minScore);
        $ctes[] = sprintf(
            "page AS (\n    SELECT id, r_text, r_fuzzy, relevance, exact_bonus, prefix_bonus, boost_bonus, recency_bonus,\n        relevance + exact_bonus + prefix_bonus + boost_bonus + recency_bonus AS score\n    FROM scored\n    WHERE relevance >= %s\n    ORDER BY score DESC, id\n    LIMIT %d OFFSET %d\n)",
            $minScore,
            $limit,
            $offset,
        );

        // The meta row always exists, so totals are known even for pages past the end.
        $sql = sprintf(
            "WITH %s\nSELECT m.total, m.fts_n, m.fuzzy_n, page.*\nFROM (SELECT (SELECT count(*) FROM scored WHERE relevance >= %s) AS total, %s) AS m\nLEFT JOIN page ON TRUE\nORDER BY page.score DESC NULLS LAST, page.id",
            implode(",\n", $ctes),
            $minScore,
            implode(', ', $counts),
        );

        return ['sql' => $sql, 'params' => $params->all()];
    }

    /**
     * The empty-result relaxation probe: per leaf, whether at least one document of the searched
     * set (filters and tenant included) matches it on its own, with the condition the search
     * uses for it. One row, one boolean column l<i> per leaf (NULL for a stop word); each EXISTS
     * stops at the first row of its materialized CTE.
     *
     * @param list<Node>      $leaves
     * @param bool            $fuzzy        whether the fuzzy branch is eligible (then long enough words may match by trigram)
     * @param list<Condition> $conditions
     * @param list<string>    $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
     *
     * @return array{sql: string, params: array<string, scalar|null>}
     */
    public function probe(array $leaves, bool $fuzzy, array $conditions, Thresholds $thresholds, array $emptyQueries = []): array
    {
        $params = new ParameterBag();
        $filters = new FilterCompiler($this->index);
        $table = Sql::ident($this->index->sidecarTable());
        $compiled = (new FuzzyQueryCompiler($this->index, $thresholds))->leafConditions($leaves, $params, $emptyQueries, $fuzzy);
        if ($compiled['columns'] === []) {
            throw new \LogicException('A relaxation probe needs at least one leaf that is not a stop word.');
        }

        // One MATERIALIZED CTE per leaf: it is planned for full retrieval, so the planner reads the
        // GIN indexes (bitmap scans) instead of a scan that stops at the first match, which reads
        // the whole table for a word that matches nothing; EXISTS over it still stops at the first
        // row. Planner settings (enable_seqscan ...) would do the same, but they would leak into
        // the caller's transaction.
        $ctes = ['q AS MATERIALIZED (SELECT ' . implode(', ', $compiled['columns']) . ')'];
        $columns = [];
        foreach ($compiled['predicates'] as $i => $predicate) {
            if ($predicate === null) {
                $columns[] = sprintf('    NULL::boolean AS l%d', $i);

                continue;
            }
            $ctes[] = sprintf('m%d AS MATERIALIZED (SELECT 1 FROM %s AS s CROSS JOIN q WHERE %s AND %s)', $i, $table, $predicate, $filters->compile($conditions, $params));
            $columns[] = sprintf('    EXISTS (SELECT 1 FROM m%1$d) AS l%1$d', $i);
        }
        $sql = sprintf("WITH %s
SELECT
%s", implode(",
     ", $ctes), implode(",
", $columns));

        return ['sql' => $sql, 'params' => $params->all()];
    }

    /**
     * No search text: filter-only browsing, ordered by boost / recency, then id.
     *
     * @param list<Condition> $conditions
     *
     * @return array{sql: string, params: array<string, scalar|null>}
     */
    public function browse(array $conditions, RankingProfile $profile, Thresholds $thresholds, int $limit, int $offset): array
    {
        $params = new ParameterBag();
        $where = (new FilterCompiler($this->index))->compile($conditions, $params);
        $table = Sql::ident($this->index->sidecarTable());
        $score = sprintf('(%s + %s)', $this->boostExpression($profile), $this->recencyExpression($profile));

        $sql = sprintf(
            "WITH b AS (\n    SELECT s.id, %s AS boost_bonus, %s AS recency_bonus, %s AS sort_score\n    FROM %s AS s\n    WHERE %s\n    ORDER BY sort_score DESC, s.id\n    LIMIT %d\n), page AS (\n    SELECT id, 0::double precision AS r_text, 0::double precision AS r_fuzzy, 0::double precision AS relevance,\n        0::double precision AS exact_bonus, 0::double precision AS prefix_bonus, boost_bonus, recency_bonus,\n        boost_bonus + recency_bonus AS score\n    FROM b\n    ORDER BY score DESC, id\n    LIMIT %d OFFSET %d\n)\nSELECT m.total, 0 AS fts_n, 0 AS fuzzy_n, page.*\nFROM (SELECT count(*) AS total FROM b) AS m\nLEFT JOIN page ON TRUE\nORDER BY page.score DESC NULLS LAST, page.id",
            $this->boostExpression($profile),
            $this->recencyExpression($profile),
            $score,
            $table,
            $where,
            $thresholds->candidateLimit,
            $limit,
            $offset,
        );

        return ['sql' => $sql, 'params' => $params->all()];
    }

    private function boostExpression(RankingProfile $profile): string
    {
        return $this->index->boostColumn !== null && $profile->boost > 0.0
            ? sprintf('%s * coalesce(s.boost, 0)', Sql::float($profile->boost))
            : '0::double precision';
    }

    /** Exponential decay: a document $halfLife days old gets half of the recency weight. */
    private function recencyExpression(RankingProfile $profile): string
    {
        if ($this->index->recencyColumn === null || $profile->recency <= 0.0) {
            return '0::double precision';
        }

        return sprintf(
            'coalesce(%s * exp(-0.6931471805599453 * greatest(extract(epoch FROM (now() - s.recency_at)), 0) / (86400.0 * %s)), 0)',
            Sql::float($profile->recency),
            Sql::float($profile->recencyHalfLifeDays),
        );
    }
}
