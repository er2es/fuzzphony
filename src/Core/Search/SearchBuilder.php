<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\InvalidQuery;
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
        private ?SearchGate $gate = null,
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

    /**
     * Searches as this actor: its tenant scopes a tenant-scoped index (it is ignored on any other index), its
     * user is carried for the search log and the guards. The last of forTenant() / asActor() wins; an actor
     * without a tenant leaves a tenant set before alone. An integer tenant (a resolver reading `account.id`)
     * on a string tenant filter is turned into its string; any other pairing is left as it is.
     */
    public function asActor(Actor $actor): self
    {
        $query = $this->query->withActor($actor);
        if ($this->index->tenant !== null && $actor->tenant !== null) {
            $tenant = $actor->tenant;
            if (is_int($tenant) && $this->index->filter($this->index->tenant)->type === FilterType::String) {
                $tenant = (string) $tenant;
            }
            $query = $query->forTenant($tenant);
        }

        return $this->with($query);
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

    /**
     * Keeps this search out of the search log (for example the requests of a live search box that fire on every
     * pause in typing); a refusal is still recorded.
     */
    public function unlogged(): self
    {
        return $this->with($this->query->unlogged());
    }

    /**
     * Searches, ranks and highlights only these fields (the guards use it for fields a viewer may not see). A word
     * that exists only in another field finds nothing; calling it again can only narrow the list.
     */
    public function restrictedToFields(string ...$fields): self
    {
        $known = $this->index->fieldNames();
        $unknown = array_values(array_diff($fields, $known));
        if ($unknown !== []) {
            throw new InvalidQuery(sprintf('Index "%s" has no field "%s". Known fields: %s.', $this->index->name, $unknown[0], implode(', ', $known)));
        }
        $allowed = $this->query->allowedFields === null ? array_values(array_unique($fields)) : array_values(array_intersect($this->query->allowedFields, $fields));

        return $this->with($this->query->restrictedTo($allowed));
    }

    public function get(): SearchResult
    {
        return $this->run(true);
    }

    /** @internal A federated search charges the rate limit once, with its first index. */
    public function getUncharged(): SearchResult
    {
        return $this->run(false);
    }

    private function run(bool $charge): SearchResult
    {
        $query = $this->effective();
        $result = $this->gate !== null
            ? $this->gate->search($this->engine, $this->index, $query, $this->query->text, $charge)
            : $this->engine->search($this->index, self::checked($this->index, $query));

        return $this->query->asYouType && $result->didYouMean !== null ? $result->withDidYouMean(Typeahead::withoutPrefixAlternative($result->didYouMean)) : $result;
    }

    public function explain(bool $analyze = false): Explanation
    {
        $query = $this->effective();
        $explanation = $this->gate !== null
            ? $this->gate->explain($this->engine, $this->index, $query, $analyze, $this->query->text, false)
            : $this->engine->explain($this->index, self::checked($this->index, $query), $analyze);
        $didYouMean = $explanation->result->didYouMean;
        if (!$this->query->asYouType || $didYouMean === null) {
            return $explanation;
        }

        return new Explanation($explanation->interpretedAs, $explanation->statements, $explanation->plan, $explanation->result->withDidYouMean(Typeahead::withoutPrefixAlternative($didYouMean)));
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

    private static function checked(IndexDefinition $index, SearchQuery $query): SearchQuery
    {
        SearchGate::assertActorHasTenant($index, $query);

        return $query;
    }

    private function with(SearchQuery $query): self
    {
        return new self($this->engine, $this->index, $query, $this->gate);
    }
}
