<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Search;

use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\Filter\Condition;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\ManyMatchFields;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Engine\Postgres\Sql\SearchSqlBuilder;

/**
 * @internal What one search is made of once its query is validated: the index, the query, the conditions (the tenant's first), the
 * profile and thresholds in force, and the one place that builds the SQL builders and compilers for it, so they are all made alike.
 */
final readonly class SearchContext
{
    /**
     * @param list<Condition>        $conditions
     * @param \Closure(): bool       $openClass    whether the open-class indexes may serve a restricted viewer (TextConfigCache::openClass())
     * @param array<string, string>  $coupled      the coupled filters whose column is in the database (TextConfigCache::sameRow()), see coupled()
     */
    public function __construct(
        public IndexDefinition $index,
        public SearchQuery $query,
        public array $conditions,
        public RankingProfile $profile,
        public Thresholds $thresholds,
        public Names $names,
        private \Closure $openClass,
        private array $coupled = [],
    ) {}

    /**
     * @param array<string, list<int|string>>                  $candidates  the ids the trigram words match (FuzzyQueryCompiler::probes())
     * @param array<string, array{word: string, score: float}> $corrections typed word => the word it most likely meant (Suggestion::$corrections)
     */
    public function builder(array $candidates = [], array $corrections = []): SearchSqlBuilder
    {
        return new SearchSqlBuilder($this->index, $this->names, $candidates, $this->query->allowedFields, true, openClass: $this->openClass, bestValues: $this->bestValueFields(), single: $this->single(), coupled: $this->coupled, corrections: $corrections);
    }

    /**
     * The `same_row_as` filters this search holds to the value that holds the words (SameRow::coupled(): only under manyMatch('single') and
     * only for a field the viewer sees), less those whose column is not in the database (a rolling deploy: TextConfigCache::sameRow()),
     * which keep the document-level filter.
     *
     * @return array<string, string> filter name => field name
     */
    public function coupled(): array
    {
        return $this->coupled;
    }

    /**
     * The rank: best fields whose best value the ranked statement reports for the page: every one with explainMatches()
     * (ScoreBreakdown::$bestValues), else the highlighted ones (the value shown); the builder keeps only those the viewer may see.
     *
     * @return list<string>
     */
    public function bestValueFields(): array
    {
        $best = array_map(static fn(FieldDefinition $f): string => $f->name, $this->index->manyBestFields());

        return $this->query->explainMatches ? $best : array_values(array_intersect($best, $this->query->highlight));
    }

    /**
     * manyMatch('single'): the rank: best fields of which one value must hold the words, for THIS viewer (ManyMatchFields: the named or
     * every visible one; [] for the default mode and for a viewer who sees none, whose statements are the default ones).
     *
     * @return list<string>
     */
    public function single(): array
    {
        return ManyMatchFields::resolve($this->index, $this->query);
    }

    public function fuzzy(): FuzzyQueryCompiler
    {
        return new FuzzyQueryCompiler($this->index, $this->thresholds, $this->names, [], $this->query->allowedFields, true, openClass: $this->openClass);
    }
}
