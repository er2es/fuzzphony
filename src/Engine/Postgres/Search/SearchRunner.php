<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Search;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
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
use Fuzzphony\Core\Ranking\FuzzyMode;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\ScoreBreakdown;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\EngineGuard;
use Fuzzphony\Engine\Postgres\Highlighter;
use Fuzzphony\Engine\Postgres\Hints;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\SearchSession;
use Fuzzphony\Engine\Postgres\Sql\FilterCompiler;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Engine\Postgres\Sql\SearchSqlBuilder;
use Fuzzphony\Engine\Postgres\Sql\TsQueryCompiler;
use Fuzzphony\Engine\Postgres\Statement;
use Fuzzphony\Engine\Postgres\StatementKind;
use Fuzzphony\Engine\Postgres\TextConfigCache;
use Fuzzphony\Engine\Postgres\TypoPlan;
use Fuzzphony\Engine\Postgres\VocabularyService;

/**
 * @internal The search of the PostgreSQL engine: validates and parses the query, runs the strict and the typo-tolerant statements,
 * the empty-result relaxation, the exact total, the facets, the did-you-mean lookup and the zero-result rescue, and turns the rows
 * into hits, highlights and a SearchResult. The statements are built by SearchSqlBuilder; SearchSession runs them.
 */
final readonly class SearchRunner
{
    private const string EXACT_LABEL = 'exact total';
    private const string RESTRICTED_PLAN = 'No plan for a search restricted to some fields: its estimates and row counts are taken over every field.';

    public function __construct(
        private Connection $connection,
        private Names $names,
        private SearchSession $session,
        private TextConfigCache $caches,
        private VocabularyService $vocabulary,
        private FacetRunner $facets,
        private EngineGuard $guard,
    ) {}

    public function search(IndexDefinition $index, SearchQuery $query): SearchResult
    {
        return $this->execute($index, $query)->result;
    }

    public function explain(IndexDefinition $index, SearchQuery $query, bool $analyze): Explanation
    {
        $run = $this->execute($index, $query);
        $plan = [];
        // the plan of the last search statement (the relaxation probe and the suggestion lookup are listed but are not the search)
        $last = null;
        foreach ($run->statements as $statement) {
            if ($statement->kind->isSearch()) {
                $last = $statement;
            }
        }
        if ($last !== null && $query->allowedFields !== null) {
            // The planner's row estimates (the statistics of tsv and fz) and EXPLAIN ANALYZE's row counts ("Rows Removed by
            // Filter" after GIN(tsv)) count the words of every field: they would tell a restricted viewer how many
            // documents have a word in a field it may not see. The statements are shown; the plan is not.
            $plan = [self::RESTRICTED_PLAN];
        } elseif ($last !== null) {
            $plan = $this->guard->run('explain', fn(): array => $this->session->plan($last, $run->threshold, $analyze), Hints::INDEX);
        }

        return new Explanation($run->result->interpretedAs ?? '', array_map(static fn(Statement $s): array => $s->toArray(), $run->statements), $plan, $run->result);
    }

    /**
     * One search, with the zero-result rescue: a search that found nothing, and has a word with a unique one-edit spelling
     * in the vocabulary, is run once more with the corrected words (never again: the second run cannot rescue) and its
     * result, flagged, is the answer when it found something without the relaxation dropping a word. See executeOnce() for when a search is rescued.
     */
    private function execute(IndexDefinition $index, SearchQuery $query): SearchRun
    {
        $started = hrtime(true);
        $run = $this->executeOnce($index, $query, true);
        if ($run->rescue !== null) {
            $second = $this->executeOnce($index, $query->withText($run->rescue['to']), false);
            // The corrected search must find the whole corrected query: when it only found something after the relaxation dropped
            // a word ("mp use" -> "mp usb" -> "usb"), the rescue would compound two guesses and say "showing results for
            // 'mp usb'" about hits that are for "usb" alone, so the original empty answer stands.
            if ($second->result->total > 0 && !$second->relaxed) {
                $run = $this->rescued($query, $run, $second, $run->rescue, $started);
                $this->guard->metrics()->increment('fuzzphony.search.rescued', ['index' => $index->name]);
            } else {
                // nothing either: the answer is the original, empty one; explain() still lists what the attempt ran
                $run = new SearchRun($run->result, [...$run->statements, ...self::rescueLabels($second->statements)], $second->threshold);
            }
        }
        $this->guard->metrics()->observe('fuzzphony.search.took_ms', $run->result->tookMs, ['index' => $index->name]);
        if (array_any($run->statements, static fn(Statement $s): bool => $s->kind === StatementKind::Fallback)) {
            $this->guard->metrics()->increment('fuzzphony.search.fallback', ['index' => $index->name]);
        }

        return new SearchRun($run->result, $run->statements, $run->threshold);
    }

    /**
     * The answer of a rescued search: the corrected search's hits, total and facets, flagged with what the visitor typed, with
     * the statements of both (the second's labelled "rescue: ...") and a warning that says what happened.
     *
     * @param array{from: string, to: string} $rescue the typed query and the corrected one, both as the parser reads them (bounded, sanitised)
     */
    private function rescued(SearchQuery $query, SearchRun $first, SearchRun $second, array $rescue, int|float $started): SearchRun
    {
        $inner = $second->result;

        return new SearchRun(
            new SearchResult(
                hits: $inner->hits,
                total: $inner->total,
                totalIsLowerBound: $inner->totalIsLowerBound,
                tookMs: round((hrtime(true) - $started) / 1e6, 3),
                usedFuzzy: $inner->usedFuzzy,
                warnings: array_values(array_unique([...$first->result->warnings, ...$inner->warnings, sprintf("No results for '%s'; showing results for '%s'.", $rescue['from'], $rescue['to'])])),
                limit: $inner->limit,
                offset: $inner->offset,
                interpretedAs: $inner->interpretedAs,
                didYouMean: null,
                facets: $inner->facets,
                correctedFrom: $query->text,
            ),
            [...$first->statements, ...self::rescueLabels($second->statements)],
            $second->threshold,
        );
    }

    /**
     * @param list<Statement> $statements
     *
     * @return list<Statement>
     */
    private static function rescueLabels(array $statements): array
    {
        return array_map(static fn(Statement $statement): Statement => $statement->labelled('rescue: '), $statements);
    }

    /**
     * One search without a rescue of its own (`$rescue` false, the run of a rescue, also has no relaxation and no suggestion: see above). A search is to be rescued ("rescue" is set) when it found nothing (after the
     * relaxation and the typo-tolerant branch), $rescue allows it (the second run of a rescue does not), `rescue_empty` and
     * `did_you_mean` are on, typo tolerance is not switched off (`fuzzy_mode: never`, a profile with `fuzzy` 0), the text is not a search-as-you-type one (the last word is unfinished: nothing is wrong with it),
     * the viewer is not restricted and the index is not tenant-scoped (the vocabulary is the words of every document,
     * hidden fields and other tenants' included: suggest() never looks it up for them), and every word that needs a
     * correction has exactly one vocabulary word one edit away (suggest()). "from" and "to" are the typed and the corrected
     * query as the parser reads them.
     */
    private function executeOnce(IndexDefinition $index, SearchQuery $query, bool $rescue): SearchRun
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

            return SearchRun::empty(SearchResult::empty($query->limit, $query->offset, $warnings, round((hrtime(true) - $started) / 1e6, 3)));
        }
        if ($root !== null && !NodeInspector::hasPositive($root)) {
            // "-cable" alone would mean "everything except ..." which is rarely intended and never cheap.
            $warnings[] = 'The search only excluded words; add at least one word to look for.';

            return SearchRun::empty(SearchResult::empty($query->limit, $query->offset, $warnings, round((hrtime(true) - $started) / 1e6, 3)));
        }

        $typedRoot = $root;
        if ($root !== null && !$index->synonyms->isEmpty()) {
            $root = $this->caches->expandSynonyms($index, $root, $thresholds, $warnings);
        }
        $expandedRoot = $root;

        $context = new SearchContext($index, $query, $conditions, $profile, $thresholds, $this->names, $this->caches->cappedColumn($index), $this->caches->openClass($index));
        $run = $this->pipeline($context, $root, '', true);
        // What is left of the search (the typo-tolerant statements, the relaxation, the facets, the suggestion) runs in ONE transaction
        // when typo tolerance is due (see SearchSession::inTransaction()); a search that needs no setting has no transaction at all.
        $finish = fn(): Completion => $this->finish($context, $run, $root, $typedRoot, $expandedRoot, $warnings, $rescue);
        $done = $run->phase !== null ? $this->session->inTransaction($finish) : $finish();
        $root = $done->root;
        $run = $done->run;
        $rows = $done->rows;

        $highlights = $this->highlights($context, $done);

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
            total: $done->total,
            totalIsLowerBound: $done->capped,
            tookMs: round((hrtime(true) - $started) / 1e6, 3),
            usedFuzzy: $run->usedFuzzy,
            warnings: array_values(array_unique($done->warnings)),
            limit: $query->limit,
            offset: $query->offset,
            interpretedAs: $root !== null ? (string) $root : null,
            didYouMean: $done->didYouMean,
            facets: $done->facets,
        );

        return new SearchRun($result, $done->statements, $done->threshold, $done->rescue, $done->relaxed);
    }

    /**
     * The statements that follow the first one: the typo-tolerant phase, the relaxation, the exact total, the facets, the
     * suggestion; and the rescue the search is due.
     *
     * @param list<string> $warnings
     */
    private function finish(SearchContext $c, PipelineRun $first, ?Node $root, ?Node $typedRoot, ?Node $expandedRoot, array $warnings, bool $rescue): Completion
    {
        $query = $c->query;
        $thresholds = $c->thresholds;
        $run = $first->completed();
        $statements = $run->statements;
        array_push($warnings, ...$run->warnings);
        // setting of the last search statement, which explain() runs again
        $threshold = $run->threshold;

        // Empty-result relaxation: drop the words that match nothing on their own, search once more.
        $relaxedUsed = false;
        if ($root !== null && $thresholds->relaxWhenEmpty && self::total($run->rows) === 0) {
            $attempt = $this->relax($c, $root, $run);
            if ($attempt !== null) {
                array_push($statements, ...$attempt->statements);
                if ($attempt->run !== null) {
                    $threshold = $attempt->run->threshold;
                }
                // Still nothing: the user would be told words were ignored and see no result anyway,
                // so the original (empty) answer stands.
                if ($attempt->warning !== null && $attempt->run !== null && $attempt->reduced !== null) {
                    $root = $attempt->reduced;
                    $relaxedUsed = true;
                    $run = $attempt->run;
                    array_push($warnings, ...$run->warnings);
                    $warnings[] = $attempt->warning;
                }
            }
        }

        $rows = $run->rows;
        $total = self::total($rows);
        // both branches select `window` candidates (more than the candidate limit for a page past it)
        $window = SearchSqlBuilder::window($thresholds, $query->limit, $query->offset);
        $capped = $rows !== [] && (Coerce::int($rows[0]['fts_n']) >= $window || Coerce::int($rows[0]['fuzzy_n']) >= $window);
        if ($run->browse) {
            $capped = $total >= $window;
        }
        $builder = $c->builder($run->ranked->candidates ?? []);
        // exactCounts(): when the candidate limit cut the matches off, count them all
        if ($query->exactCounts && $capped) {
            $ranked = $run->ranked;
            $statement = Statement::of(StatementKind::ExactTotal, self::EXACT_LABEL, $ranked === null
                ? $builder->browseExactTotal($c->conditions)
                : $builder->exactTotal($ranked->tsquery, $ranked->plain, $ranked->fuzzyRoot, $c->conditions, $c->profile, $thresholds, $ranked->emptyQueries, $ranked->scopedRoot));
            $total = Coerce::int($this->session->run($statement, $threshold)[0]['total'] ?? 0);
            $statements[] = $statement;
            $capped = false;
        }
        $faceted = $this->facets->run($c, $run, $builder, $threshold, $rows);
        array_push($statements, ...$faceted->statements);
        $rows = self::hitsOnly($rows);

        $suggestion = null;
        // the vocabulary holds the words of every field: a restricted viewer gets no suggestion (nor the lookup's statement)
        if ($thresholds->didYouMean && $query->allowedFields === null && $typedRoot !== null && $expandedRoot !== null && !$run->browse && $c->index->hasFuzzy() && ($total < $thresholds->fallbackBelow || $run->usedFuzzy)) {
            $suggestion = $this->vocabulary->suggest($c->index, $typedRoot, $expandedRoot, $thresholds);
            if ($suggestion->statement !== null) {
                $statements[] = $suggestion->statement;
            }
        }
        // Only a search that found nothing is rescued, and a search-as-you-type text never (its last word is not finished). It is
        // a typo correction: a search that switched typo tolerance off (fuzzy_mode never, a profile without fuzzy weight) has none.
        $oneEdit = $suggestion?->oneEdit;
        $rescueWith = $rescue && $total === 0 && $thresholds->rescueEmpty && $thresholds->fuzzyMode !== FuzzyMode::Never && $c->profile->fuzzy > 0.0 && !$query->asYouType && $typedRoot !== null && $oneEdit !== null
            ? ['from' => QueryRenderer::render($typedRoot), 'to' => $oneEdit]
            : null;

        return new Completion($run, $statements, $warnings, $threshold, $root, $rows, $run->tsquery, $total, $capped, $faceted->facets, $suggestion?->didYouMean, $rescueWith, $relaxedUsed);
    }

    /**
     * The empty-result relaxation: the probe says which words match nothing on their own, and the search runs once more without them.
     * Null when there is nothing to probe (see probe()).
     */
    private function relax(SearchContext $c, Node $root, PipelineRun $run): ?RelaxAttempt
    {
        $probe = $this->probe($c, $root, $run->fuzzy, $run->emptyQueries);
        if ($probe === null) {
            return null;
        }
        $reduced = $probe['ignored'] === [] ? null : Relaxation::without($root, $probe['ignored']);
        // the same guard as for the query itself: a relaxed query must still look for something
        if ($reduced === null || !NodeInspector::hasPositive($reduced)) {
            return new RelaxAttempt([$probe['statement']]);
        }
        $relaxed = $this->pipeline($c, $reduced, 'relaxed: ');

        return new RelaxAttempt(
            [$probe['statement'], ...$relaxed->statements],
            $relaxed,
            $reduced,
            self::total($relaxed->rows) > 0 ? Relaxation::warning($probe['ignored']) : null,
        );
    }

    /**
     * The normal search: strict full text, then the typo-tolerant branch as the fuzzy mode says
     * (or browsing when there is no text). Runs once, or twice when an empty result is relaxed.
     *
     * With $defer the typo-tolerant branch is not run but returned in `phase`: the caller runs the rest of the search inside one
     * transaction (see PipelineRun::completed()). Without $defer the phase runs here, in a transaction of its own.
     */
    private function pipeline(SearchContext $c, ?Node $root, string $labelPrefix, bool $defer = false): PipelineRun
    {
        $index = $c->index;
        $query = $c->query;
        $thresholds = $c->thresholds;
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
        $fuzzy = $c->fuzzy();
        $fuzzyRoot = $root !== null
            && $index->hasFuzzy()
            && $c->profile->fuzzy > 0.0
            && $thresholds->fuzzyMode !== FuzzyMode::Never
            && $fuzzy->hasFuzzyLeaf($root) ? $root : null;

        // "the who -rock": PostgreSQL reduces 'the' & 'who' & !'rock' to !'rock', "everything but rock", so the strict branch
        // gets the stop words alone (as for "the who": nothing matches) and the typo-tolerant branch searches them, rock excluded
        if ($fuzzyRoot instanceof AllOf && $tsquery !== null && array_any($fuzzyRoot->nodes, static fn(Node $n): bool => $n instanceof Not)
            && ($stopWords = $fuzzy->exclusionStopWords($fuzzyRoot, $this->caches->emptyQueries($index, $fuzzy->leafQueries($fuzzyRoot)))) !== null) {
            $tsquery = (new TsQueryCompiler($index))->compile($stopWords);
        }

        $builder = $c->builder();
        // the facets that keep every condition count the very candidates of the page's own selection (see SearchSqlBuilder::facetsOf()), unless
        // the page is past the candidate limit (its selection is larger, and the facets of a search must not change with the page) or the
        // counts are exact (they read every match)
        $withIds = $query->facets !== [] && !$query->exactCounts && SearchSqlBuilder::window($thresholds, $query->limit, $query->offset) === $thresholds->candidateLimit;
        $statements = [];
        $threshold = null;
        $browse = false;
        $ranked = null;
        $phase = null;
        $rows = [];
        // the recheck drops stop words as the strict tsquery does, so it needs them up front
        $emptyQueries = $scopedRoot === null ? null : $this->fuzzyStopWords($c, $fuzzy, $scopedRoot, $fuzzyRoot);
        // the typo-tolerant statements: the candidates of each word probed on its own, then the ranked statement over them
        $typoPhase = fn(Node $fuzzyRoot, StatementKind $kind, string $label, array $empty): \Closure => fn(): PhaseResult => $this->session->inTransaction(function () use ($c, $fuzzy, $fuzzyRoot, $kind, $label, $empty, $tsquery, $plain, $scopedRoot, $withIds): PhaseResult {
            /** @var list<string> $empty */
            $candidates = $this->fuzzyCandidates($c, $fuzzy, $fuzzyRoot, $empty);
            $statement = Statement::of($kind, $label, $c->builder($candidates)->ranked($tsquery, $plain, $fuzzyRoot, $c->conditions, $c->profile, $c->thresholds, $c->query->limit, $c->query->offset, $empty, $scopedRoot, $withIds));
            $threshold = $fuzzy->lowestSimilarity($fuzzyRoot, $empty);
            $rows = $this->session->run($statement, $threshold);

            return new PhaseResult($rows, [$statement], $threshold, new RankedSearch($tsquery, $plain, $fuzzyRoot, $empty, $scopedRoot, $candidates));
        });

        if ($tsquery === null && $plain === '') {
            $statement = Statement::of(StatementKind::Search, 'browse', $builder->browse($c->conditions, $c->profile, $thresholds, $query->limit, $query->offset));
            $rows = $this->session->run($statement, null);
            $statements[] = $statement;
            $browse = true;
        } else {
            $alwaysFuzzy = $fuzzyRoot !== null
                && ($thresholds->fuzzyMode === FuzzyMode::Always || $tsquery === null)
                && $fuzzy->hasFuzzyLeaf($fuzzyRoot, $emptyQueries ??= $this->fuzzyStopWords($c, $fuzzy, $fuzzyRoot, $fuzzyRoot));
            if ($alwaysFuzzy && $fuzzyRoot !== null) {
                $phase = $typoPhase($fuzzyRoot, StatementKind::Search, $labelPrefix . 'full-text + fuzzy', $emptyQueries ?? []);
            } else {
                $statement = Statement::of(StatementKind::Search, $labelPrefix . 'full-text', $builder->ranked($tsquery, $plain, null, $c->conditions, $c->profile, $thresholds, $query->limit, $query->offset, $emptyQueries ?? [], $scopedRoot, $withIds, FacetRunner::carriable($c)));
                $rows = $this->session->run($statement, null);
                $statements[] = $statement;
                $ranked = new RankedSearch($tsquery, $plain, null, $emptyQueries ?? [], $scopedRoot, []);

                if (
                    $fuzzyRoot !== null
                    && $thresholds->fuzzyMode === FuzzyMode::Fallback
                    && self::total($rows) < $thresholds->fallbackBelow
                    && $fuzzy->hasFuzzyLeaf($fuzzyRoot, $emptyQueries ??= $this->fuzzyStopWords($c, $fuzzy, $fuzzyRoot, $fuzzyRoot))
                ) {
                    $phase = $typoPhase($fuzzyRoot, StatementKind::Fallback, $labelPrefix . 'fallback: full-text + fuzzy', $emptyQueries);
                }
            }
        }

        $run = new PipelineRun($rows, $statements, false, $threshold, $tsquery, $fuzzyRoot !== null, $emptyQueries, $browse, $warnings, $ranked, $phase);

        return $defer ? $run : $run->completed();
    }

    /**
     * The relaxation probe: which positive words match no document of the searched set on their
     * own, with the condition the search used for them (filters and tenant included). Null when
     * there is nothing to probe: fewer than two words that are not stop words (stop words are
     * ignored by the search already and are never reported). "ignored" is empty when every word
     * matches something, and when none does (then there is nothing to keep).
     *
     * @param list<string>|null $emptyQueries the stop-word tsqueries when the search asked for them already, else null
     *
     * @return array{
     *     statement: Statement,
     *     ignored: list<Term|Phrase|FieldScoped|AnyOf>
     * }|null
     */
    private function probe(SearchContext $c, Node $root, bool $fuzzy, ?array $emptyQueries): ?array
    {
        $index = $c->index;
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
        $empty = $emptyQueries ?? $this->caches->emptyQueries($index, array_values(array_unique($tsqueries)));
        $probed = [];
        foreach ($leaves as $i => $leaf) {
            if (!in_array($tsqueries[$i], $empty, true)) {
                $probed[] = $leaf;
            }
        }
        if (count($probed) < 2) {
            return null;
        }

        $statement = Statement::of(StatementKind::Probe, 'relaxation probe', $c->builder()->probe($probed, $fuzzy, $c->conditions, $c->thresholds, $empty));
        $row = $this->session->run($statement, $fuzzy ? $c->fuzzy()->lowestSimilarity(new AllOf($probed), $empty) : null)[0] ?? [];
        $ignored = [];
        foreach ($probed as $i => $leaf) {
            if (in_array($row['l' . $i] ?? null, [false, 'f', 0], true)) {
                $ignored[] = $leaf;
            }
        }

        return ['statement' => $statement, 'ignored' => count($ignored) < count($probed) ? $ignored : []];
    }

    /**
     * The stop-word leaf tsqueries a search drops (TextConfigCache::emptyQueries()), except, for a query of stop words only and when the
     * typo-tolerant branch can run, its positive words: that branch matches them on the normalised text, where the
     * stop words are (FuzzyQueryCompiler::keepingStopWords()). A query with a real word is as it was.
     *
     * @return list<string>
     */
    private function fuzzyStopWords(SearchContext $c, FuzzyQueryCompiler $fuzzy, Node $query, ?Node $fuzzyRoot): array
    {
        $empty = $this->caches->emptyQueries($c->index, $fuzzy->leafQueries($query));

        return $fuzzyRoot === null || $empty === [] ? $empty : $fuzzy->keepingStopWords($fuzzyRoot, $empty);
    }

    /**
     * The documents each typo-tolerant word matches on its own, asked word by word at the word's own similarity:
     * with the session's threshold set for the lowest word of the whole statement, the trigram index would return
     * (and PostgreSQL read) every row that merely starts alike for a word that needs more (see FuzzyQueryCompiler).
     * A word with more matches than the candidate limit is left out of `candidates` and keeps its trigram condition.
     *
     * Each probe applies the search's conditions (tenant and filters): only documents the search could return are probed.
     *
     * @param list<string> $emptyQueries
     *
     * @return array<string, list<int|string>> by candidateKey()
     */
    private function fuzzyCandidates(SearchContext $c, FuzzyQueryCompiler $fuzzy, Node $root, array $emptyQueries): array
    {
        $cap = $c->thresholds->candidateLimit;
        $candidates = [];
        foreach ($fuzzy->probes($root, $cap, $emptyQueries, $c->conditions) as $key => $probe) {
            $ids = array_map(static fn(array $row): int|string => is_int($row['id']) ? $row['id'] : Coerce::str($row['id']), $this->session->runProbe($probe['sql'], $probe['params'], $probe['similarity']));
            sort($ids); // the probe has no order: the ids are bound into statements (explain() shows them), which must not follow the heap
            if (count($ids) <= $cap) {
                $candidates[$key] = $ids;
            }
        }

        return $candidates;
    }

    /**
     * The highlights of the hits: ts_headline for the words of the strict query, and for a hit found by typo tolerance alone the
     * words that were mistyped (a hit without text rank was not found by the strict branch).
     *
     * @return array<string, array<string, string>>
     */
    private function highlights(SearchContext $c, Completion $done): array
    {
        $query = $c->query;
        $index = $c->index;
        $run = $done->run;
        $rows = $done->rows;
        // a field the viewer may not see is dropped from the highlights (not an error: the application asks the same for everyone)
        $highlighted = $query->allowedFields === null ? $query->highlight : array_values(array_intersect($query->highlight, $query->allowedFields));
        if ($highlighted === [] || $done->tsquery === null || $rows === []) {
            return [];
        }
        // a hit without text rank was not found by the strict branch but by typo tolerance: ts_headline marks none of the words that were mistyped
        $typo = null;
        if ($run->usedFuzzy && $done->root !== null) {
            $typoIds = [];
            foreach ($rows as $r) {
                if (Coerce::float($r['r_text']) <= 0.0) {
                    $typoIds[] = $index->idType->cast(Coerce::str($r['id']));
                }
            }
            $terms = $typoIds === [] ? [] : (new FuzzyQueryCompiler($index, $c->thresholds, $this->names, [], $query->allowedFields, true))->typoTerms($done->root, $run->emptyQueries ?? []);
            $typo = $terms === [] ? null : new TypoPlan($terms, $typoIds, $c->thresholds->fuzzySimilarity, $query->allowedFields);
        }

        return $this->guard->run('highlighting', fn(): array => (new Highlighter($this->connection, $this->names))->highlight(
            $index,
            $highlighted,
            $done->tsquery,
            array_map(static fn(array $r): int|string => $index->idType->cast(Coerce::str($r['id'])), $rows),
            $typo,
        ), Hints::INDEX);
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
}
