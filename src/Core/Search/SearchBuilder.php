<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Query\SearchQuery;

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

    public function get(): SearchResult
    {
        return $this->engine->search($this->index, $this->query);
    }

    public function explain(bool $analyze = false): Explanation
    {
        return $this->engine->explain($this->index, $this->query, $analyze);
    }

    public function toQuery(): SearchQuery
    {
        return $this->query;
    }

    private function with(SearchQuery $query): self
    {
        return new self($this->engine, $this->index, $query);
    }
}
