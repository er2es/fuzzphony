<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Sql;

use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Filter\Condition;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Schema\Names;

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
    /**
     * @param list<string>|null $allowedFields the fields a restricted viewer may see (SearchQuery::$allowedFields); null =
     *                                         every field, the unrestricted statement. Restricted: every word is rechecked
     *                                         against the allowed fields (FuzzyQueryCompiler), the text rank reads only
     *                                         their vectors, the exact and prefix bonuses are 0 unless the primary field
     *                                         is allowed, and the full-text branch needs the parsed query ($scopedRoot)
     */
    public function __construct(
        private readonly IndexDefinition $index,
        private readonly Names $names = new Names(),
        /** @var array<string, list<int|string>> the ids the trigram words match, from FuzzyQueryCompiler::probes() */
        private readonly array $candidates = [],
        private readonly ?array $allowedFields = null,
    ) {}

    /**
     * @param string          $plain        positive words, for the exact / prefix bonuses (q.norm)
     * @param Node|null       $fuzzyRoot    the parsed query when the fuzzy branch runs, else null
     * @param list<Condition> $conditions
     * @param list<string>    $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
     * @param Node|null       $scopedRoot   the parsed query when it has a word scoped to a known field (rechecked in the full-text branch), else null
     *
     * @return array{sql: string, params: array<string, scalar|null>}
     */
    public function ranked(?string $tsquery, string $plain, ?Node $fuzzyRoot, array $conditions, RankingProfile $profile, Thresholds $thresholds, int $limit, int $offset, array $emptyQueries = [], ?Node $scopedRoot = null): array
    {
        ['ctes' => $ctes, 'params' => $params, 'fuzzy' => $fuzzy] = $this->scored($tsquery, $plain, $fuzzyRoot, $conditions, $profile, $thresholds, $emptyQueries, $scopedRoot, false);

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
     * The CTEs up to `scored` (see the class comment); `$uncapped` drops the candidate limit, for exact counts.
     *
     * @param list<Condition> $conditions
     * @param list<string>    $emptyQueries
     *
     * @return array{ctes: list<string>, params: ParameterBag, fuzzy: ?FuzzyMatch}
     */
    private function scored(?string $tsquery, string $plain, ?Node $fuzzyRoot, array $conditions, RankingProfile $profile, Thresholds $thresholds, array $emptyQueries, ?Node $scopedRoot, bool $uncapped): array
    {
        $params = new ParameterBag();
        $filters = new FilterCompiler($this->index);
        $table = $this->names->sidecar($this->index);
        $config = $this->names->regconfig($this->index->text);
        // an exact count reads every match; a search and its approximate counts stop at the candidate limit
        $cap = $uncapped ? '' : "\n    LIMIT " . $thresholds->candidateLimit;

        $q = [];
        // the full-text branch finds its candidates through GIN(tsv): "s.tsv @@ <lookup>"
        $lookup = 's.tsv @@ q.tsq';
        if ($tsquery !== null) {
            $q[] = sprintf('to_tsquery(%s, %s) AS tsq', $config, $params->add($tsquery));
            if ($this->allowedFields !== null) {
                // An excluded word would exclude by every field, the hidden ones too: the lookup is the positive part
                // only (a superset), the recheck below decides; nothing positive to look up leaves the recheck alone.
                $positive = (new TsQueryCompiler($this->index, true))->compile($scopedRoot ?? throw new \LogicException('A restricted full-text branch needs the parsed query to recheck.'));
                if ($positive === null) {
                    $lookup = null;
                } elseif ($positive !== $tsquery) {
                    $q[] = sprintf('to_tsquery(%s, %s) AS tsp', $config, $params->add($positive));
                    $lookup = 's.tsv @@ q.tsp';
                }
            }
        }
        $q[] = $plain !== ''
            ? sprintf('%s(%s) AS norm', $this->names->normFunction(), $params->add($plain))
            : "''::text AS norm";
        $compiler = new FuzzyQueryCompiler($this->index, $thresholds, $this->names, $this->candidates, $this->allowedFields);
        // A word scoped to a known field is rechecked against the field's own column (q.sft<n>); a restricted viewer's
        // every word against the allowed fields' columns.
        $scope = $scopedRoot === null ? null : $compiler->scope($scopedRoot, $params, $emptyQueries);
        if ($scope !== null) {
            array_push($q, ...$scope['columns']);
        }
        $strict = array_values(array_filter([
            $lookup,
            // a restricted query of stop words only has nothing to recheck: it finds nothing, as its tsquery does
            $scope['predicate'] ?? ($this->allowedFields !== null ? 'false' : null),
        ], static fn(?string $part): bool => $part !== null));
        // Per-term fuzzy branch: every word is satisfied exactly or fuzzily, through the query's
        // own AND / OR / NOT. Its per-word values are extra q columns.
        $fuzzy = $fuzzyRoot === null ? null : $compiler->compile($fuzzyRoot, $params, $emptyQueries);
        if ($fuzzy !== null) {
            array_push($q, ...$fuzzy->columns);
        }
        // MATERIALIZED: one row computed once, and the planner must not see the per-word values (see FuzzyQueryCompiler)
        $ctes = ['q AS MATERIALIZED (SELECT ' . implode(', ', $q) . ')'];

        $branches = [];
        if ($tsquery !== null) {
            $ctes[] = sprintf(
                "fts AS (\n    SELECT s.id, ts_rank_cd('%s'::real[], %s, q.tsq, 32)::double precision AS r_text\n    FROM %s AS s CROSS JOIN q\n    WHERE %s AND %s%s\n)",
                self::tsRankWeights($profile),
                $this->rankVector(),
                $table,
                implode(' AND ', $strict),
                $filters->compile($conditions, $params),
                $cap,
            );
            $branches[] = 'SELECT id, r_text, 0::double precision AS r_fuzzy FROM fts';
        }
        if ($fuzzy !== null) {
            $ctes[] = sprintf(
                "fuzzy AS (\n    SELECT s.id, (%s)::double precision AS r_fuzzy\n    FROM %s AS s CROSS JOIN q\n    WHERE %s AND %s%s\n)",
                $fuzzy->score,
                $table,
                $fuzzy->predicate,
                $filters->compile($conditions, $params),
                $cap,
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
        // the exact column is the primary field's text: a viewer who may not see it gets no bonus from it
        $primaryAllowed = $this->allowedFields === null || in_array($this->index->primaryField()->name, $this->allowedFields, true);
        $ctes[] = sprintf(
            "scored AS (\n    SELECT c.id, c.r_text, c.r_fuzzy,\n        %s * c.r_text + %s * c.r_fuzzy AS relevance,\n        %s AS exact_bonus,\n        %s AS prefix_bonus,\n        %s AS boost_bonus,\n        %s AS recency_bonus\n    FROM cand AS c JOIN %s AS s ON s.id = c.id CROSS JOIN q\n)",
            Sql::float($profile->text),
            Sql::float($profile->fuzzy),
            $primaryAllowed ? sprintf("CASE WHEN q.norm <> '' AND s.exact = q.norm THEN %s ELSE 0 END::double precision", Sql::float($profile->exactBonus)) : '0::double precision',
            $primaryAllowed ? sprintf("CASE WHEN q.norm <> '' AND s.exact <> q.norm AND left(s.exact, length(q.norm)) = q.norm THEN %s ELSE 0 END::double precision", Sql::float($profile->prefixBonus)) : '0::double precision',
            $this->boostExpression($profile),
            $this->recencyExpression($profile),
            $table,
        );

        return ['ctes' => $ctes, 'params' => $params, 'fuzzy' => $fuzzy];
    }

    /**
     * The values of one filter among the matches of a ranked search, with how many documents have each
     * (the facet). `$conditions` are the search's own without the ones on this filter: a facet shows what
     * choosing another value would find. With `$exact` it reads every match, not only the candidates.
     *
     * @param list<Condition> $conditions
     * @param list<string>    $emptyQueries
     *
     * @return array{sql: string, params: array<string, scalar|null>}
     */
    public function facet(string $filter, ?string $tsquery, string $plain, ?Node $fuzzyRoot, array $conditions, RankingProfile $profile, Thresholds $thresholds, int $values, bool $exact, array $emptyQueries = [], ?Node $scopedRoot = null): array
    {
        ['ctes' => $ctes, 'params' => $params] = $this->scored($tsquery, $plain, $fuzzyRoot, $conditions, $profile, $thresholds, $emptyQueries, $scopedRoot, $exact);
        $column = 's.' . FilterCompiler::column($filter);
        $sql = sprintf(
            "WITH %s\nSELECT %s AS value, count(*) AS n\nFROM scored AS sc JOIN %s AS s ON s.id = sc.id\nWHERE sc.relevance >= %s\nGROUP BY %s\nORDER BY n DESC, value NULLS LAST\nLIMIT %d",
            implode(",\n", $ctes),
            $column,
            $this->names->sidecar($this->index),
            Sql::float($thresholds->minScore),
            $column,
            $values,
        );

        return ['sql' => $sql, 'params' => $params->all()];
    }

    /**
     * Several facets in one statement: the candidates are evaluated once (`fc` holds the filter column of each facet for
     * every match) and each facet is one `GROUP BY` branch, tagged with its position in `$filters` (`f`). For the facets
     * whose conditions are the search's own (a facet that leaves a condition out needs its own candidates: see facet()).
     * Rows: f, rk (the facet's own order: count descending, then the value), value (as text), n; ordered by f, rk.
     *
     * @param non-empty-list<string> $filters
     * @param list<Condition>        $conditions
     * @param list<string>           $emptyQueries
     *
     * @return array{sql: string, params: array<string, scalar|null>}
     */
    public function facets(array $filters, ?string $tsquery, string $plain, ?Node $fuzzyRoot, array $conditions, RankingProfile $profile, Thresholds $thresholds, int $values, bool $exact, array $emptyQueries = [], ?Node $scopedRoot = null): array
    {
        ['ctes' => $ctes, 'params' => $params] = $this->scored($tsquery, $plain, $fuzzyRoot, $conditions, $profile, $thresholds, $emptyQueries, $scopedRoot, $exact);
        $ctes[] = sprintf(
            "fc AS (
    SELECT %s
    FROM scored AS sc JOIN %s AS s ON s.id = sc.id
    WHERE sc.relevance >= %s
)",
            self::facetColumns($filters),
            $this->names->sidecar($this->index),
            Sql::float($thresholds->minScore),
        );

        return ['sql' => $this->facetBranches($ctes, $filters, $values), 'params' => $params->all()];
    }

    /**
     * The facets of a search without text in one statement (see facets()).
     *
     * @param non-empty-list<string> $filters
     * @param list<Condition>        $conditions
     *
     * @return array{sql: string, params: array<string, scalar|null>}
     */
    public function browseFacets(array $filters, array $conditions, Thresholds $thresholds, int $values, bool $exact): array
    {
        $params = new ParameterBag();
        $ctes = [sprintf(
            "fc AS (
    SELECT %s FROM %s AS s WHERE %s%s
)",
            self::facetColumns($filters),
            $this->names->sidecar($this->index),
            (new FilterCompiler($this->index))->compile($conditions, $params),
            $exact ? '' : "
    LIMIT " . $thresholds->candidateLimit,
        )];

        return ['sql' => $this->facetBranches($ctes, $filters, $values), 'params' => $params->all()];
    }

    /**
     * @param non-empty-list<string> $filters
     */
    private static function facetColumns(array $filters): string
    {
        return implode(', ', array_map(static fn(string $filter, int $i): string => sprintf('s.%s AS v%d', FilterCompiler::column($filter), $i), $filters, array_keys($filters)));
    }

    /**
     * One GROUP BY branch per facet over `fc`: the same order and limit as facet() (count descending, then the value in
     * its own type, NULL last), kept in `rk`; the value travels as text (a bool as 1 / 0) so the branches can be united.
     *
     * @param list<string>           $ctes
     * @param non-empty-list<string> $filters
     */
    private function facetBranches(array $ctes, array $filters, int $values): string
    {
        $branches = [];
        foreach ($filters as $i => $filter) {
            $cast = $this->index->filter($filter)->type === FilterType::Bool ? 'g.value::int::text' : 'g.value::text';
            $branches[] = sprintf(
                'SELECT %1$d AS f, g.rk, %2$s AS value, g.n FROM (SELECT v%1$d AS value, count(*) AS n, row_number() OVER (ORDER BY count(*) DESC, v%1$d NULLS LAST) AS rk FROM fc GROUP BY v%1$d ORDER BY rk LIMIT %3$d) AS g',
                $i,
                $cast,
                $values,
            );
        }

        return sprintf("WITH %s
%s
ORDER BY f, rk", implode(",
", $ctes), implode("
UNION ALL
", $branches));
    }

    /**
     * The exact number of documents a ranked search finds, without the candidate limit.
     *
     * @param list<Condition> $conditions
     * @param list<string>    $emptyQueries
     *
     * @return array{sql: string, params: array<string, scalar|null>}
     */
    public function exactTotal(?string $tsquery, string $plain, ?Node $fuzzyRoot, array $conditions, RankingProfile $profile, Thresholds $thresholds, array $emptyQueries = [], ?Node $scopedRoot = null): array
    {
        ['ctes' => $ctes, 'params' => $params] = $this->scored($tsquery, $plain, $fuzzyRoot, $conditions, $profile, $thresholds, $emptyQueries, $scopedRoot, true);
        $sql = sprintf("WITH %s\nSELECT count(*) AS total FROM scored WHERE relevance >= %s", implode(",\n", $ctes), Sql::float($thresholds->minScore));

        return ['sql' => $sql, 'params' => $params->all()];
    }

    /**
     * The facet of a search without text (filter-only browsing).
     *
     * @param list<Condition> $conditions
     *
     * @return array{sql: string, params: array<string, scalar|null>}
     */
    public function browseFacet(string $filter, array $conditions, Thresholds $thresholds, int $values, bool $exact): array
    {
        $params = new ParameterBag();
        $column = FilterCompiler::column($filter);
        $sql = sprintf(
            "WITH b AS (\n    SELECT s.%s AS value FROM %s AS s WHERE %s%s\n)\nSELECT value, count(*) AS n FROM b GROUP BY value ORDER BY n DESC, value NULLS LAST LIMIT %d",
            $column,
            $this->names->sidecar($this->index),
            (new FilterCompiler($this->index))->compile($conditions, $params),
            $exact ? '' : "\n    LIMIT " . $thresholds->candidateLimit,
            $values,
        );

        return ['sql' => $sql, 'params' => $params->all()];
    }

    /**
     * The exact number of documents a search without text finds.
     *
     * @param list<Condition> $conditions
     *
     * @return array{sql: string, params: array<string, scalar|null>}
     */
    public function browseExactTotal(array $conditions): array
    {
        $params = new ParameterBag();
        $sql = sprintf('SELECT count(*) AS total FROM %s AS s WHERE %s', $this->names->sidecar($this->index), (new FilterCompiler($this->index))->compile($conditions, $params));

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
        $table = $this->names->sidecar($this->index);
        $compiled = (new FuzzyQueryCompiler($this->index, $thresholds, $this->names, [], $this->allowedFields))->leafConditions($leaves, $params, $emptyQueries, $fuzzy);
        if (array_filter($compiled['predicates'], static fn(?string $p): bool => $p !== null) === []) {
            throw new \LogicException('A relaxation probe needs at least one leaf that is not a stop word.');
        }
        // a restricted viewer's word on a field it may not see is "false", with no value: q still needs a column
        if ($compiled['columns'] === []) {
            $compiled['columns'] = ['NULL AS none'];
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
        $table = $this->names->sidecar($this->index);
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

    /** The vector the text rank reads: the whole tsv, or only the fields a restricted viewer may see. */
    private function rankVector(): string
    {
        $allowed = $this->allowedFields;
        if ($allowed === null) {
            return 's.tsv';
        }
        $parts = array_map(
            fn(string $name): string => 's.' . $this->names->fieldVector($name),
            array_values(array_filter($this->index->fieldNames(), static fn(string $name): bool => in_array($name, $allowed, true))),
        );

        return match (count($parts)) {
            0 => "''::tsvector",
            1 => $parts[0],
            default => '(' . implode(' || ', $parts) . ')',
        };
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

    /** PostgreSQL ts_rank weights array literal, ordered {D, C, B, A}. */
    private static function tsRankWeights(RankingProfile $profile): string
    {
        $w = $profile->labelWeights;

        return sprintf('{%s,%s,%s,%s}', Sql::float($w['D']), Sql::float($w['C']), Sql::float($w['B']), Sql::float($w['A']));
    }
}
