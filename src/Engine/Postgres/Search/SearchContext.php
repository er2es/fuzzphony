<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Search;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\Filter\Condition;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
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
     * @param \Closure(): bool       $cappedColumn whether the sidecar has the tsv_capped column (TextConfigCache::cappedColumn())
     * @param \Closure(): bool       $openClass    whether the open-class indexes may serve a restricted viewer (TextConfigCache::openClass())
     */
    public function __construct(
        public IndexDefinition $index,
        public SearchQuery $query,
        public array $conditions,
        public RankingProfile $profile,
        public Thresholds $thresholds,
        public Names $names,
        private \Closure $cappedColumn,
        private \Closure $openClass,
    ) {}

    /**
     * @param array<string, list<int|string>> $candidates the ids the trigram words match (FuzzyQueryCompiler::probes())
     */
    public function builder(array $candidates = []): SearchSqlBuilder
    {
        return new SearchSqlBuilder($this->index, $this->names, $candidates, $this->query->allowedFields, true, cappedColumn: $this->cappedColumn, openClass: $this->openClass);
    }

    public function fuzzy(): FuzzyQueryCompiler
    {
        return new FuzzyQueryCompiler($this->index, $this->thresholds, $this->names, [], $this->query->allowedFields, true, cappedColumn: $this->cappedColumn, openClass: $this->openClass);
    }
}
