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
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Schema\Types;

/**
 * @internal Builds the ranked search statement:
 *
 *   q      -> the compiled query (bound parameters, evaluated once)
 *   fts    -> full-text candidates via the GIN(tsv) index, capped by candidateLimit; when the cap is hit, the best of them by
 *             the page's own sort key (see scored())
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
        /** Whether a typed word also matches with two neighbouring letters swapped (FuzzyQueryCompiler, Transpositions). */
        private readonly bool $transpositions = false,
        /**
         * @internal How many matches the ordered selection scores (see scored()); only a test makes it smaller.
         *
         * @var positive-int
         */
        private readonly int $scanBudget = self::SCAN_BUDGET,
        /** @var (\Closure(): bool)|null whether the sidecar has the tsv_capped column (FuzzyQueryCompiler), asked when a phrase or a scoped word needs it */
        private readonly ?\Closure $cappedColumn = null,
        /**
         * @var array<string, list<int|string>> for a typo-tolerant word with more matches than the candidate limit: the ids of the documents
         *                                      at least as close as a raised similarity (FuzzyQueryCompiler::probes() with levels, by
         *                                      candidateKey()), at most a few times the limit; the ordered selection of the typo branch
         *                                      reads the word from them, so its pool does not depend on the order of the heap
         */
        private readonly array $pool = [],
    ) {}

    /**
     * The most matches the ordered selection of a capped full-text branch scores: the full relevance is computed for the first
     * this many matches in index order and the best of those are the candidates, so the work of one search stays bounded
     * however many documents a word matches. Exact up to this many matches, approximate beyond (docs/limitations.md). Not
     * configurable: it is a cost bound, and every setting would be one more way to slow a search down.
     */
    private const int SCAN_BUDGET = 100_000;

    /**
     * The most matches the ordered selection of a capped typo-tolerant branch scores (divided by the number of words, like the
     * strict one): a document of this branch costs about seven times what a full-text match does, because every trigram match is
     * rechecked on the text of the typo-tolerant fields (about 8 microseconds on 200 000 short names), so the budget is smaller.
     * A word with a pool (see $pool) has at most a few times the candidate limit of documents, far under it; the budget bounds the
     * cost of a word that is itself very common (its exact matches), beyond it the matches are taken in index order. Not
     * configurable, for the reason SCAN_BUDGET is not.
     */
    private const int TYPO_SCAN_BUDGET = 30_000;

    /**
     * How many matches the ordered selection scans for a query that looks `$operands` words up: the cost of ranking a document grows
     * with the words, so the budget of one word is divided by their number (a work budget, not a row budget), but it never drops
     * under the window (`$window`: the candidates asked for).
     */
    public static function scanLimit(int $budget, int $operands, int $window): int
    {
        return max($window, intdiv($budget, max(1, $operands)));
    }

    /**
     * How many full-text candidates a search selects: the candidate limit, or more for a page past it (the first
     * `offset + limit` of the best), at most Thresholds::MAX_CANDIDATE_LIMIT.
     */
    public static function window(Thresholds $thresholds, int $limit, int $offset): int
    {
        return min(Thresholds::MAX_CANDIDATE_LIMIT, max($thresholds->candidateLimit, $offset + $limit));
    }

    /**
     * @param string          $plain        positive words, for the exact / prefix bonuses (q.norm)
     * @param Node|null       $fuzzyRoot    the parsed query when the fuzzy branch runs, else null
     * @param list<Condition> $conditions
     * @param list<string>    $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
     * @param Node|null       $scopedRoot   the parsed query when it has a word scoped to a known field (rechecked in the full-text branch), else null
     *
     * @param bool            $withCandidates also return the ids of every candidate that passes the minimum score, as JSON in the meta row's
     *                                        `cand_ids`: the facets of the search count exactly these documents (facetsOf())
     *
     * @return array{sql: string, params: array<string, scalar|null>}
     */
    public function ranked(?string $tsquery, string $plain, ?Node $fuzzyRoot, array $conditions, RankingProfile $profile, Thresholds $thresholds, int $limit, int $offset, array $emptyQueries = [], ?Node $scopedRoot = null, bool $withCandidates = false): array
    {
        ['ctes' => $ctes, 'params' => $params, 'fuzzy' => $fuzzy, 'fast' => $fast, 'fuzzyFast' => $fuzzyFast] = $this->scored($tsquery, $plain, $fuzzyRoot, $conditions, $profile, $thresholds, $emptyQueries, $scopedRoot, false, self::window($thresholds, $limit, $offset));

        $counts = [];
        // what the fast selection found: it hit its limit exactly when the matches were cut off, whatever the ordered one kept
        $counts[] = $tsquery !== null ? sprintf('(SELECT count(*) FROM %s) AS fts_n', $fast) : '0 AS fts_n';
        $counts[] = $fuzzy !== null ? sprintf('(SELECT count(*) FROM %s) AS fuzzy_n', $fuzzyFast) : '0 AS fuzzy_n';
        $minScore = Sql::float($thresholds->minScore);
        if ($withCandidates) {
            $counts[] = sprintf('(SELECT json_agg(id) FROM scored WHERE relevance >= %s) AS cand_ids', $minScore);
        }
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
     * `$window` (the ranked search and the facets; an exact count keeps the fast selection) selects the candidates by quality when
     * the matches are more than the window: `fts_fast` is the first `$window` matches the index returns (the cheap selection), and
     * `fts` is that when they are fewer, else the best `$window` by the page's own sort key, scored over the first SCAN_BUDGET
     * matches. The typo-tolerant branch does the same (`fuzzy_fast`, `fuzzy`) with the candidate limit as its window and the
     * sort key of a document found by typo tolerance alone. A search with fewer matches never runs the second select (its guard
     * is false: "never executed" in EXPLAIN ANALYZE). The second has the same lookup, recheck, tenant and filter conditions, the
     * text rank of the viewer's own vectors and the same bonus expressions as `scored`, so a viewer who may not see a field is
     * ordered as if it did not exist.
     *
     * @param list<Condition> $conditions
     * @param list<string>    $emptyQueries
     *
     * @return array{ctes: list<string>, params: ParameterBag, fuzzy: ?FuzzyMatch, fast: string, fuzzyFast: string}
     */
    private function scored(?string $tsquery, string $plain, ?Node $fuzzyRoot, array $conditions, RankingProfile $profile, Thresholds $thresholds, array $emptyQueries, ?Node $scopedRoot, bool $uncapped, ?int $window = null): array
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
        // A phrase or a scoped word of an unrestricted viewer on an index with several fields: tsv reads them in one vector whose
        // positions are capped at 16 383, so after a long field they and their weights are unreliable (see TsQueryCompiler); the
        // candidates are then looked up in the form a restricted viewer's are, and the recheck decides
        $positional = $this->allowedFields === null && $scopedRoot !== null && count($this->index->fields) > 1
            && (new TsQueryCompiler($this->index))->dependsOnPositions($scopedRoot);
        // the text rank looks every word of its query up in every document: the query without its exclusions (an excluded word is not in a
        // document that matches, so the rank is the same), see TsQueryCompiler::withoutExclusions()
        $rankQuery = 'q.tsq';
        $operands = 1;
        if ($tsquery !== null) {
            $q[] = sprintf('to_tsquery(%s, %s) AS tsq', $config, $params->add($tsquery));
            $rankForm = TsQueryCompiler::withoutExclusions($tsquery);
            if ($rankForm !== $tsquery) {
                $q[] = sprintf('to_tsquery(%s, %s) AS tsr', $config, $params->add($rankForm));
                $rankQuery = 'q.tsr';
            }
            $operands = TsQueryCompiler::operands($rankForm);
            if ($this->allowedFields !== null || $positional) {
                // An excluded word would exclude by every field, the hidden ones too: the lookup is the positive part
                // only (a superset), the recheck below decides; nothing positive to look up leaves the recheck alone.
                // The lookup is in the form a hidden field cannot change: phrases as the AND of their words, no weight labels (the
                // positions of tsv are capped at 16 383, so a long hidden field in front of an allowed one would decide whether a
                // phrase or a weighted word of the allowed field is found there; see TsQueryCompiler).
                $positive = (new TsQueryCompiler($this->index, true, true))->compile($scopedRoot ?? throw new \LogicException('A restricted full-text branch needs the parsed query to recheck.'));
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
        $compiler = new FuzzyQueryCompiler($this->index, $thresholds, $this->names, $this->candidates, $this->allowedFields, $this->transpositions, $this->cappedColumn);
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
        $ordered = $window !== null && !$uncapped;
        $poolFuzzy = null;
        $fuzzy = $fuzzyRoot === null ? null : $compiler->compile($fuzzyRoot, $params, $emptyQueries);
        if ($fuzzy !== null) {
            array_push($q, ...$fuzzy->columns);
            if ($ordered && $this->pool !== []) {
                // the ordered selection reads the words that have a pool from their ids: the same words, compiled once more
                $poolFuzzy = (new FuzzyQueryCompiler($this->index, $thresholds, $this->names, $this->candidates + $this->pool, $this->allowedFields, $this->transpositions, $this->cappedColumn))->compile($fuzzyRoot ?? throw new \LogicException('A pool needs the parsed query.'), $params, $emptyQueries, 'p');
                if ($poolFuzzy !== null) {
                    array_push($q, ...$poolFuzzy->columns);
                }
            }
        }
        // MATERIALIZED: one row computed once, and the planner must not see the per-word values (see FuzzyQueryCompiler)
        $ctes = ['q AS MATERIALIZED (SELECT ' . implode(', ', $q) . ')'];

        $branches = [];
        // the exact column is the primary field's text: a viewer who may not see it gets no bonus from it
        $primaryAllowed = $this->allowedFields === null || in_array($this->index->primaryField()->name, $this->allowedFields, true);
        $bonuses = [
            $primaryAllowed ? sprintf("CASE WHEN q.norm <> '' AND s.exact = q.norm THEN %s ELSE 0 END::double precision", Sql::float($profile->exactBonus)) : '0::double precision',
            $primaryAllowed ? sprintf("CASE WHEN q.norm <> '' AND s.exact <> q.norm AND left(s.exact, length(q.norm)) = q.norm THEN %s ELSE 0 END::double precision", Sql::float($profile->prefixBonus)) : '0::double precision',
            $this->boostExpression($profile),
            $this->recencyExpression($profile),
        ];
        $filterSql = $filters->compile($conditions, $params);
        $fast = 'fts';
        if ($tsquery !== null) {
            $fast = $ordered ? 'fts_fast' : 'fts';
            $rank = $this->textRank($profile, $positional, $rankQuery) . '::double precision';
            $where = implode(' AND ', $strict) . ' AND ' . $filterSql;
            $ctes[] = sprintf(
                "%s AS (
    SELECT s.id, %s AS r_text
    FROM %s AS s CROSS JOIN q
    WHERE %s%s
)",
                $fast,
                $rank,
                $table,
                $where,
                $ordered ? "
    LIMIT " . $window : $cap,
            );
            if ($ordered) {
                $n = (int) $window;
                // a minimum relevance keeps the documents under it out of the slots; with typo tolerance in the same statement a
                // document may pass on its typo similarity, so the fast selection's rule (no cut here) stands
                $min = $fuzzy === null && $thresholds->minScore > 0.0 ? sprintf(' AND %s * w.r_text >= %s', Sql::float($profile->text), Sql::float($thresholds->minScore)) : '';
                $ctes[] = sprintf(
                    "fts AS (
    SELECT id, r_text FROM fts_fast WHERE (SELECT count(*) FROM fts_fast) < %1\$d
    UNION ALL
    SELECT id, r_text FROM (
        SELECT w.id, w.r_text FROM (
            SELECT s.id, %2\$s AS r_text, %3\$s AS exact_bonus, %4\$s AS prefix_bonus, %5\$s AS boost_bonus, %6\$s AS recency_bonus
            FROM %7\$s AS s CROSS JOIN q
            WHERE %8\$s
            LIMIT %9\$d
        ) AS w
        WHERE (SELECT count(*) FROM fts_fast) >= %1\$d%10\$s
        ORDER BY %11\$s * w.r_text + w.exact_bonus + w.prefix_bonus + w.boost_bonus + w.recency_bonus DESC, w.id
        LIMIT %1\$d
    ) AS o
)",
                    $n,
                    $rank,
                    $bonuses[0],
                    $bonuses[1],
                    $bonuses[2],
                    $bonuses[3],
                    $table,
                    $where,
                    self::scanLimit($this->scanBudget, $operands, $n),
                    $min,
                    Sql::float($profile->text),
                );
            }
            $branches[] = 'SELECT id, r_text, 0::double precision AS r_fuzzy FROM fts';
        }
        $fuzzyFast = 'fuzzy';
        if ($fuzzy !== null) {
            $fuzzyFast = $ordered ? 'fuzzy_fast' : 'fuzzy';
            $ctes[] = sprintf(
                "%s AS (
    SELECT s.id, (%s)::double precision AS r_fuzzy
    FROM %s AS s CROSS JOIN q
    WHERE %s AND %s%s
)",
                $fuzzyFast,
                $fuzzy->score,
                $table,
                $fuzzy->predicate,
                $filterSql,
                $cap,
            );
            if ($ordered) {
                $limit = $thresholds->candidateLimit;
                $columns = static fn(FuzzyMatch $by): string => sprintf('%s AS r_fuzzy', '(' . $by->score . ')::double precision');
                $select = fn(FuzzyMatch $by, string $guard): string => sprintf(
                    "SELECT s.id, %1\$s, %2\$s AS exact_bonus, %3\$s AS prefix_bonus, %4\$s AS boost_bonus, %5\$s AS recency_bonus
            FROM %6\$s AS s CROSS JOIN q
            WHERE %7\$s AND %8\$s%9\$s",
                    $columns($by),
                    $bonuses[0],
                    $bonuses[1],
                    $bonuses[2],
                    $bonuses[3],
                    $table,
                    $by->predicate,
                    $filterSql,
                    $guard,
                );
                $scan = self::scanLimit(min($this->scanBudget, self::TYPO_SCAN_BUDGET), $fuzzy->words, $limit);
                if ($poolFuzzy === null) {
                    $inner = $select($fuzzy, '') . "
            LIMIT " . $scan;
                } else {
                    // The pool: the documents at least as close as the pool level of every very common word (their ids). When it holds
                    // enough, it IS the selection (nothing depends on the order of the heap any more); when it holds fewer, the first
                    // matches of the scan fill the slots, and the pool is still in.
                    $ctes[] = sprintf("fuzzy_pool AS (
    %s
)", $select($poolFuzzy, sprintf(' AND (SELECT count(*) FROM fuzzy_fast) >= %d', $limit)));
                    $inner = sprintf("SELECT * FROM fuzzy_pool
            UNION
            (%s
            LIMIT %d)", $select($fuzzy, sprintf(' AND (SELECT count(*) FROM fuzzy_pool) < %d', $limit)), $scan);
                }
                $ctes[] = sprintf(
                    "fuzzy AS (
    SELECT id, r_fuzzy FROM fuzzy_fast WHERE (SELECT count(*) FROM fuzzy_fast) < %1\$d
    UNION ALL
    SELECT id, r_fuzzy FROM (
        SELECT w.id, w.r_fuzzy FROM (
            %2\$s
        ) AS w
        WHERE (SELECT count(*) FROM fuzzy_fast) >= %1\$d
        ORDER BY %3\$s * w.r_fuzzy + w.exact_bonus + w.prefix_bonus + w.boost_bonus + w.recency_bonus DESC, w.id
        LIMIT %1\$d
    ) AS o
)",
                    $limit,
                    $inner,
                    Sql::float($profile->fuzzy),
                );
            }
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
            "scored AS (
    SELECT c.id, c.r_text, c.r_fuzzy,
        %s * c.r_text + %s * c.r_fuzzy AS relevance,
        %s AS exact_bonus,
        %s AS prefix_bonus,
        %s AS boost_bonus,
        %s AS recency_bonus
    FROM cand AS c JOIN %s AS s ON s.id = c.id CROSS JOIN q
)",
            Sql::float($profile->text),
            Sql::float($profile->fuzzy),
            $bonuses[0],
            $bonuses[1],
            $bonuses[2],
            $bonuses[3],
            $table,
        );

        return ['ctes' => $ctes, 'params' => $params, 'fuzzy' => $fuzzy, 'fast' => $fast, 'fuzzyFast' => $fuzzyFast];
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
        ['ctes' => $ctes, 'params' => $params] = $this->scored($tsquery, $plain, $fuzzyRoot, $conditions, $profile, $thresholds, $emptyQueries, $scopedRoot, $exact, $thresholds->candidateLimit);
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
        ['ctes' => $ctes, 'params' => $params] = $this->scored($tsquery, $plain, $fuzzyRoot, $conditions, $profile, $thresholds, $emptyQueries, $scopedRoot, $exact, $thresholds->candidateLimit);
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
     * The facets over the very documents a ranked search selected (its `cand_ids`, see ranked()): the same candidates as the hits, found
     * once, and no second selection that could differ. One statement for any number of facets, in the form of facets().
     *
     * @param list<int|string>       $ids
     * @param non-empty-list<string> $filters
     *
     * @return array{sql: string, params: array<string, scalar|null>}
     */
    public function facetsOf(array $ids, array $filters, int $values): array
    {
        $params = new ParameterBag();
        $ctes = [sprintf(
            "fc AS (
    SELECT %s
    FROM %s AS s
    WHERE s.id = ANY(CAST(%s AS %s[]))
)",
            self::facetColumns($filters),
            $this->names->sidecar($this->index),
            $params->add(Sql::arrayLiteral($ids)),
            Types::id($this->index->idType),
        )];

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
            $exact ? '' : self::firstById($thresholds->candidateLimit),
        )];

        return ['sql' => $this->facetBranches($ctes, $filters, $values), 'params' => $params->all()];
    }

    /**
     * The documents a capped count over a search without text reads: the first `$limit` by id, whatever the order of the heap
     * (which the size of every field, a hidden one included, shapes). Lower bounds, like every capped count.
     */
    private static function firstById(int $limit): string
    {
        return "
    ORDER BY s.id
    LIMIT " . $limit;
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
            $exact ? '' : self::firstById($thresholds->candidateLimit),
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
        $compiled = (new FuzzyQueryCompiler($this->index, $thresholds, $this->names, [], $this->allowedFields, $this->transpositions, $this->cappedColumn))->leafConditions($leaves, $params, $emptyQueries, $fuzzy);
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

    /**
     * The text rank of a full-text candidate: ts_rank_cd of the vector the viewer reads (rankVector()). A document that `tsv`
     * does not match but the search found anyway (a phrase or a scoped word after a long field, see TsQueryCompiler) has no
     * usable rank on tsv, whose positions and weights were capped: it is ranked on the fields' own vectors (the best of the
     * first field's and of every run of later fields, Names::laterVectors()). A document tsv matches keeps its rank. With the
     * tsv_capped column only a document that is capped (or not known to be: NULL) can be one of those, so every other document
     * is ranked on tsv without even the check.
     */
    private function textRank(RankingProfile $profile, bool $positional, string $rankQuery = 'q.tsq'): string
    {
        $weights = self::tsRankWeights($profile);
        $rank = static fn(string $vector): string => sprintf("ts_rank_cd('%s'::real[], %s, %s, 32)", $weights, $vector, $rankQuery);
        if (!$positional) {
            return $rank($this->rankVector());
        }
        $vectors = ['s.' . $this->names->fieldVector($this->index->fieldNames()[0]), ...array_map(static fn(string $v): string => '(' . $v . ')', $this->names->laterVectors($this->index))];

        if ($this->cappedColumn !== null && ($this->cappedColumn)()) {
            // the fields' vectors come from a subquery on the document's own row (see FuzzyQueryCompiler::matches()): the other rows pay nothing
            $own = ['c.' . $this->names->fieldVector($this->index->fieldNames()[0]), ...array_map(static fn(string $v): string => '(' . $v . ')', $this->names->laterVectors($this->index, 'c'))];

            return sprintf(
                'CASE WHEN s.%s IS NOT FALSE AND NOT (s.tsv @@ q.tsq) THEN (SELECT GREATEST(%s) FROM %s AS c WHERE c.id = s.id) ELSE %s END',
                PostgresSchemaGenerator::CAPPED_COLUMN,
                implode(', ', array_map($rank, $own)),
                $this->names->sidecar($this->index),
                $rank('s.tsv'),
            );
        }

        return sprintf('CASE WHEN s.tsv @@ q.tsq THEN %s ELSE GREATEST(%s) END', $rank('s.tsv'), implode(', ', array_map($rank, $vectors)));
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
