<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\InvalidDefinition;
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
    /** @internal */
    public function __construct(
        private Engine $engine,
        private IndexDefinition $index,
        private SearchQuery $query = new SearchQuery(),
        private ?SearchGate $gate = null,
    ) {}

    public function query(string $text): self
    {
        return $this->next($this->query->withText($text));
    }

    public function where(string $filter, mixed $operatorOrValue, mixed $value = null): self
    {
        return $this->next(func_num_args() === 2 ? $this->query->where($filter, $operatorOrValue) : $this->query->where($filter, $operatorOrValue, $value));
    }

    /** @param list<mixed> $values */
    public function whereIn(string $filter, array $values): self
    {
        return $this->next($this->query->whereIn($filter, $values));
    }

    public function whereBetween(string $filter, mixed $from, mixed $to): self
    {
        return $this->next($this->query->whereBetween($filter, $from, $to));
    }

    public function whereNull(string $filter, bool $isNull = true): self
    {
        return $this->next($this->query->whereNull($filter, $isNull));
    }

    public function forTenant(mixed $value): self
    {
        return $this->next($this->query->forTenant($value));
    }

    /**
     * Searches as this actor: its tenant scopes a tenant-scoped index (it is ignored on any other index), its
     * user is carried for the search log and the guards. An actor with a tenant never searches another one:
     * a forTenant() naming a different tenant (before or after) refuses the search with AccessDenied; asSystem() is the way across tenants.
     * An actor without a tenant leaves a tenant set with forTenant() alone. An integer tenant (a resolver reading `account.id`)
     * on a string tenant filter is turned into its string; any other pairing is left as it is.
     */
    public function asActor(Actor $actor): self
    {
        return $this->next(SearchGate::withActor($this->index, $this->query, $actor));
    }

    /**
     * Searches as the system: no guard runs (every field is searched and shown), and the search log records the search as
     * made by "(system)". For jobs and commands that are allowed to see everything; a web request should name its actor with
     * asActor() or leave it out (the application's current user is used). An index with a tenant still needs one
     * (forTenant()). Refused with AccessDenied when `fuzzphony.authorization.allow_system` is false. The last of asActor() /
     * asSystem() wins.
     */
    public function asSystem(): self
    {
        return $this->next($this->query->asSystem());
    }

    /**
     * Adds, for the hits of the returned page, which word of which field matched and how (Hit::$matches). It costs one more
     * statement over the ids of the page, only when asked for, and shows no field the viewer may not see.
     */
    public function explainMatches(bool $explain = true): self
    {
        return $this->next($this->query->explainMatches($explain));
    }

    /**
     * Returns values of each hit's SOURCE row in Hit::$data (field => value), so an application can render a result row and build
     * links without loading entities. The names are a subset of the `returnable` columns the index (or the hit's source) declares; no
     * argument means all of them, calling it again adds. It costs one more statement per source on the page, read from the source
     * query by the ids of the page (always fresh, nothing is copied into the index), only when asked for. A name that is not returnable,
     * or that is a `granted` field the actor does not hold, is an InvalidQuery listing the names this actor may ask for. A field the
     * hit's source does not return is null; a hit whose row vanished meanwhile has no data. Integers, floats and booleans come back as
     * PHP ones, text and dates as strings (ISO 8601 for dates and timestamps), json and arrays as JSON strings.
     */
    public function with(string ...$fields): self
    {
        $asked = $this->query->returning;

        return $this->next($this->query->returning($asked === null || $fields === [] ? array_values($fields) : ($asked === [] ? [] : array_values(array_unique([...$asked, ...$fields])))));
    }

    public function profile(string $profile): self
    {
        $this->index->profile($profile); // fail fast on typos

        return $this->next($this->query->profile($profile));
    }

    public function highlight(string ...$fields): self
    {
        return $this->next($this->query->highlight(...$fields));
    }

    /** @param array<string, mixed> $overrides e.g. ['relevance' => ['min_score' => 0.1], 'typo' => ['mode' => 'always']] */
    public function thresholds(array $overrides): self
    {
        try {
            $this->index->thresholds->with($overrides); // validate now, not at execution time
        } catch (InvalidDefinition $e) {
            throw new InvalidQuery(sprintf('Index "%s": invalid thresholds for this query: %s', $this->index->name, implode(' ', $e->violations)), 0, $e);
        }

        return $this->next($this->query->thresholds($overrides));
    }

    /** @param array<string, mixed> $overrides e.g. ['fuzzy' => 0.8, 'boost' => 0.1] on top of the chosen profile */
    public function ranking(array $overrides): self
    {
        try {
            $this->index->profile($this->query->profile)->with($overrides); // validate now
        } catch (InvalidDefinition $e) {
            throw new InvalidQuery(sprintf('Index "%s": invalid ranking for this query: %s', $this->index->name, implode(' ', $e->violations)), 0, $e);
        }

        return $this->next($this->query->ranking($overrides));
    }

    public function limit(int $limit, int $offset = 0): self
    {
        return $this->next($this->query->limit($limit, $offset));
    }

    public function page(int $page, int $perPage = 20): self
    {
        return $this->next($this->query->page($page, $perPage));
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

        return $this->next($this->query->facets(...$filters));
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
        return $this->next($this->query->asYouType($asYouType));
    }

    /** The most values listed per facet (1 to 100, default 20). */
    public function facetValues(int $values): self
    {
        return $this->next($this->query->facetValues($values));
    }

    /**
     * Counts every match for the total and the facets, not only the candidates: exact, but it reads
     * everything the query matches, so it can be slow on a large index. Never from request input.
     */
    public function exactCounts(bool $exact = true): self
    {
        return $this->next($this->query->exactCounts($exact));
    }

    /**
     * Keeps this search out of the search log (for example the requests of a live search box that fire on every
     * pause in typing); a refusal is still recorded.
     */
    public function unlogged(): self
    {
        return $this->next($this->query->unlogged());
    }

    /**
     * Searches, ranks and highlights only these fields. It can only NARROW: the fields searched are those of the list that the actor
     * may also see (a guard's answer), so naming a `granted` field the actor does not hold adds nothing, and a list with none of the
     * allowed fields finds nothing (no exception). A word that exists only in another field finds nothing; calling it again narrows
     * the list further, never widens it. A name that is no field of the index is an InvalidQuery listing the index's fields.
     */
    public function restrictedToFields(string ...$fields): self
    {
        $known = $this->index->fieldNames();
        $unknown = array_values(array_diff($fields, $known));
        if ($unknown !== []) {
            throw new InvalidQuery(sprintf('Index "%s" has no field "%s". Known fields: %s.', $this->index->name, $unknown[0], implode(', ', $known)));
        }
        $allowed = $this->query->allowedFields === null ? array_values(array_unique($fields)) : array_values(array_intersect($this->query->allowedFields, $fields));

        return $this->next($this->query->restrictedTo($allowed));
    }

    public function get(): SearchResult
    {
        return $this->run(true);
    }

    /** @internal Never call it from application code (it skips the rate limit): the federated search uses it. */
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
            ? $this->gate->explain($this->engine, $this->index, $query, $analyze, $this->query->text)
            : $this->engine->explain($this->index, self::checked($this->index, $query), $analyze);
        $didYouMean = $explanation->result->didYouMean;
        if (!$this->query->asYouType || $didYouMean === null) {
            return $explanation;
        }

        return new Explanation($explanation->interpretedAs, $explanation->statements, $explanation->plan, $explanation->result->withDidYouMean(Typeahead::withoutPrefixAlternative($didYouMean)));
    }

    /** @internal */
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
        SearchGate::assertTenantAgrees($index, $query);

        return $query;
    }

    private function next(SearchQuery $query): self
    {
        return new self($this->engine, $this->index, $query, $this->gate);
    }
}
