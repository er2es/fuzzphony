<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Query\Typeahead;

/**
 * Fluent, immutable entry point bound to one index:
 *
 *   $fuzzphony->in(Product::class)
 *       ->query('wireless mouse -cable')
 *       ->where('price', '<', 30_000)
 *       ->highlight('name')
 *       ->get();
 */
final readonly class SearchBuilder
{
    public function __construct(
        private Engine $engine,
        private IndexDefinition $index,
        private SearchQuery $query = new SearchQuery(),
    ) {}

    public function query(string $text): self
    {
        return $this->with($this->query->withText($text));
    }

    public function where(string $filter, mixed $operatorOrValue, mixed $value = null): self
    {
        return $this->with(func_num_args() === 2 ? $this->query->where($filter, $operatorOrValue) : $this->query->where($filter, $operatorOrValue, $value));
    }

    /** @param list<mixed> $values */
    public function whereIn(string $filter, array $values): self
    {
        return $this->with($this->query->whereIn($filter, $values));
    }

    public function whereBetween(string $filter, mixed $from, mixed $to): self
    {
        return $this->with($this->query->whereBetween($filter, $from, $to));
    }

    public function whereNull(string $filter, bool $isNull = true): self
    {
        return $this->with($this->query->whereNull($filter, $isNull));
    }

    public function forTenant(mixed $value): self
    {
        return $this->with($this->query->forTenant($value));
    }

    public function profile(string $profile): self
    {
        $this->index->profile($profile); // fail fast on typos

        return $this->with($this->query->profile($profile));
    }

    public function highlight(string ...$fields): self
    {
        return $this->with($this->query->highlight(...$fields));
    }

    /** @param array<string, mixed> $overrides e.g. ['min_score' => 0.1, 'fuzzy_mode' => 'always'] */
    public function thresholds(array $overrides): self
    {
        $this->index->thresholds->with($overrides); // validate now, not at execution time

        return $this->with($this->query->thresholds($overrides));
    }

    /** @param array<string, mixed> $overrides e.g. ['fuzzy' => 0.8, 'boost' => 0.1] on top of the chosen profile */
    public function ranking(array $overrides): self
    {
        $this->index->profile($this->query->profile)->with($overrides); // validate now

        return $this->with($this->query->ranking($overrides));
    }

    public function limit(int $limit, int $offset = 0): self
    {
        return $this->with($this->query->limit($limit, $offset));
    }

    public function page(int $page, int $perPage = 20): self
    {
        return $this->with($this->query->page($page, $perPage));
    }

    /**
     * Counts the values of these filters among the matches: `$result->facets['category']`. Only string, int,
     * bool and date filters can be faceted (not the tenant filter). Every facet is one more statement.
     */
    public function facets(string ...$filters): self
    {
        foreach ($filters as $name) {
            $this->index->assertFacetable($name); // fail fast on typos and on filters that cannot be faceted
        }

        return $this->with($this->query->facets(...$filters));
    }

    /**
     * Search-as-you-type: while the text ends with a word being typed ("wireless hea"), the last word matches
     * as the word itself (typo tolerance, synonyms and "did you mean" included) or as the beginning of a longer
     * one (`hea` finds `headphones`). Without it a word is a whole word: `cr` finds nothing where `creme` is. The
     * text is left alone once it ends with a space or a symbol, inside a quoted phrase, and after an operator.
     * `interpretedAs` shows the result: `(hea OR hea*)`.
     */
    public function asYouType(bool $asYouType = true): self
    {
        return $this->with($this->query->asYouType($asYouType));
    }

    /** The most values listed per facet (1 to 100, default 20). */
    public function facetValues(int $values): self
    {
        return $this->with($this->query->facetValues($values));
    }

    /**
     * Counts every match for the total and the facets, not only the candidates: exact, but it reads
     * everything the query matches, so it can be slow on a large index. Never from request input.
     */
    public function exactCounts(bool $exact = true): self
    {
        return $this->with($this->query->exactCounts($exact));
    }

    public function get(): SearchResult
    {
        $result = $this->engine->search($this->index, $this->effective());

        return $this->query->asYouType && $result->didYouMean !== null ? $result->withDidYouMean(Typeahead::withoutPrefixAlternative($result->didYouMean)) : $result;
    }

    public function explain(bool $analyze = false): Explanation
    {
        return $this->engine->explain($this->index, $this->effective(), $analyze);
    }

    public function toQuery(): SearchQuery
    {
        return $this->query;
    }

    /** What the engine is asked: the text with its last word as a prefix too, for search-as-you-type. */
    private function effective(): SearchQuery
    {
        return $this->query->asYouType ? $this->query->withText(Typeahead::lastWordAsPrefix($this->query->text)) : $this->query;
    }

    private function with(SearchQuery $query): self
    {
        return new self($this->engine, $this->index, $query);
    }
}
