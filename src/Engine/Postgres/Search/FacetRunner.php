<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Search;

use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Query\Filter\Condition;
use Fuzzphony\Core\Search\FacetValue;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\SearchSession;
use Fuzzphony\Engine\Postgres\Sql\SearchSqlBuilder;
use Fuzzphony\Engine\Postgres\Statement;
use Fuzzphony\Engine\Postgres\StatementKind;

/**
 * @internal The facets of a search, by ONE path: the facets are grouped by the conditions they count under, and each group is one
 * statement (SearchSqlBuilder::facets(), facetsOf() or browseFacets(): one GROUP BY branch per facet).
 *
 * A facet is disjunctive: the conditions on its own filter are left out, so it shows what another value would find (the tenant's stay).
 * The facets that keep every condition count the very candidates of the page ("common": one group, the ids of the page's own
 * selection when its statement returned them, else the same selection again); every facet that leaves a condition out has a group
 * of its own and selects its own candidates.
 */
final readonly class FacetRunner
{
    public function __construct(private SearchSession $session) {}

    /**
     * @param list<array<string, mixed>> $rows      the rows of the page's statement (they carry `cand_ids` when it was asked for them)
     * @param ?float                     $threshold the setting of the last search statement, which the facet statements that select candidates need
     */
    public function run(SearchContext $c, PipelineRun $run, SearchSqlBuilder $builder, ?float $threshold, array $rows): FacetResult
    {
        $query = $c->query;
        $index = $c->index;
        if ($query->facets === []) {
            return new FacetResult([], []);
        }
        // the conditions each facet counts under
        $others = [];
        foreach (array_unique($query->facets) as $name) {
            $others[$name] = array_values(array_filter($c->conditions, static fn(Condition $condition): bool => $condition->filter !== $name || $condition->filter === $index->tenant));
        }
        /** @var array<string, non-empty-list<string>> $groups the facets of each group, by the group's first facet */
        $groups = [];
        $common = null;
        foreach ($others as $name => $conditions) {
            if ($conditions === $c->conditions) {
                $common ??= $name;
                $groups[$common][] = $name;
            } else {
                $groups[$name] = [$name];
            }
        }
        // the ids of the page's own selection, when the facets that keep every condition can count exactly those
        $ids = $run->ranked !== null && $common !== null ? self::candidateIds($rows) : null;

        $statements = [];
        $facetRows = [];
        $carried = self::carriedFacet($c, $rows);
        foreach ($groups as $first => $names) {
            $isCommon = $first === $common;
            if ($carried !== null && $first === $carried['filter']) {
                // the page's own statement counted it (SearchSqlBuilder::wideSelection())
                $facetRows[$first] = $carried['rows'];

                continue;
            }
            $statement = $this->statement($c, $run, $isCommon ? $builder : $c->builder(), $names, $others[$first], $isCommon ? $ids : null);
            // ids: the statement reads no similarity setting
            $result = $this->session->run($statement, $isCommon && $ids !== null ? null : $threshold);
            $statements[] = $statement;
            foreach ($names as $name) {
                $facetRows[$name] = [];
            }
            foreach ($result as $row) {
                $facetRows[$names[Coerce::int($row['f'])]][] = $row;
            }
        }
        $facets = [];
        foreach ($query->facets as $name) {
            $type = $index->filter($name)->type;
            $facets[$name] = array_map(static fn(array $row): FacetValue => new FacetValue(self::value($type, $row['value'] ?? null), Coerce::int($row['n'])), $facetRows[$name]);
        }

        return new FacetResult($facets, $statements);
    }

    /**
     * The facet the page's strict statement can count in the same scan: the first facet that leaves a condition out. Null when
     * the facets are not counted over the page's candidates (exact counts, a deeper page) or there is none.
     *
     * @return array{conditions: list<Condition>, filter: string, values: int}|null
     */
    public static function carriable(SearchContext $c): ?array
    {
        $query = $c->query;
        if ($query->facets === [] || $query->exactCounts || SearchSqlBuilder::window($c->thresholds, $query->limit, $query->offset) !== $c->thresholds->candidateLimit) {
            return null;
        }
        foreach ($query->facets as $name) {
            $others = array_values(array_filter($c->conditions, static fn(Condition $condition): bool => $condition->filter !== $name || $condition->filter === $c->index->tenant));
            if ($others !== $c->conditions) {
                return ['conditions' => $others, 'filter' => $name, 'values' => $query->facetValues];
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{filter: string, rows: list<array{value: mixed, n: int}>}|null
     */
    private static function carriedFacet(SearchContext $c, array $rows): ?array
    {
        $wide = self::carriable($c);
        if ($wide === null || $rows === [] || !array_key_exists('wide_facet', $rows[0])) {
            return null;
        }
        $json = $rows[0]['wide_facet'];
        $decoded = $json === null ? [] : json_decode(Coerce::str($json), true, 8, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        return ['filter' => $wide['filter'], 'rows' => array_map(static fn(mixed $pair): array => ['value' => is_array($pair) ? $pair[0] : null, 'n' => is_array($pair) ? Coerce::int($pair[1]) : 0], array_values($decoded))];
    }

    /**
     * @param non-empty-list<string> $names
     * @param list<Condition>        $conditions the conditions the group counts under
     * @param list<int|string>|null $ids         the page's own candidates, when this group counts exactly those
     */
    private function statement(SearchContext $c, PipelineRun $run, SearchSqlBuilder $builder, array $names, array $conditions, ?array $ids): Statement
    {
        $label = 'facet: ' . implode(', ', $names);
        $values = $c->query->facetValues;
        if ($ids !== null) {
            return Statement::of(StatementKind::Facet, $label, $builder->facetsOf($ids, $names, $values));
        }
        $ranked = $run->ranked;

        return Statement::of(StatementKind::Facet, $label, $ranked === null
            ? $builder->browseFacets($names, $conditions, $c->thresholds, $values, $c->query->exactCounts)
            : $builder->facets($names, $ranked->tsquery, $ranked->plain, $ranked->fuzzyRoot, $conditions, $c->profile, $c->thresholds, $values, $c->query->exactCounts, $ranked->emptyQueries, $ranked->scopedRoot));
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

    /** A facet value as PHP: the type the filter declares. */
    private static function value(FilterType $type, mixed $value): bool|int|string|null
    {
        return match (true) {
            $value === null => null,
            $type === FilterType::Bool => $value === true || $value === 't' || $value === '1' || $value === 1,
            $type === FilterType::Int => Coerce::int($value),
            default => Coerce::str($value),
        };
    }
}
