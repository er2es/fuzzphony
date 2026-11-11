<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query;

use Fuzzphony\Core\Query\Filter\Condition;
use Fuzzphony\Core\Query\Filter\Operator;

/**
 * Immutable search request. Every with*() / where*() call returns a new instance,
 * so a base query can be safely reused and extended.
 *
 * @internal
 */
final readonly class SearchQuery
{
    /**
     * @param list<Condition>      $conditions
     * @param list<string>         $highlight           field names
     * @param array<string, mixed> $thresholdOverrides per group, see Thresholds::with()
     * @param array<string, mixed> $rankingOverrides   snake_case keys, see RankingProfile::with()
     * @param list<string>         $facets             filters to count the values of among the matches
     * @param list<string>|null    $allowedFields      field names the search is limited to; null = every field
     * @param list<string>|null    $returning          returnable source columns to return with the hits; null = none, [] = all
     * @param list<string>         $manyMatchFields    many fields (rank: best) the manyMatch mode applies to; [] = every visible one
     */
    public function __construct(
        public string $text = '',
        public array $conditions = [],
        public string $profile = 'default',
        public int $limit = 20,
        public int $offset = 0,
        public array $highlight = [],
        public array $thresholdOverrides = [],
        public array $rankingOverrides = [],
        public mixed $tenant = null,
        public array $facets = [],
        /** The most values listed per facet. */
        public int $facetValues = 20,
        /** Count every match: an exact total and exact facets, not only the candidates (see LimitThresholds::$candidates). */
        public bool $exactCounts = false,
        /** The last word of the text also matches as a prefix (search-as-you-type), see Typeahead. */
        public bool $asYouType = false,
        /** Who is searching (SearchBuilder::asActor()); null = nobody was named. */
        public ?\Fuzzphony\Core\Search\Actor $actor = null,
        /** false = the search is kept out of the search log (SearchBuilder::unlogged()); a refusal is recorded all the same. */
        public bool $log = true,
        /** Searches, ranks and highlights only these fields (SearchBuilder::restrictedToFields()); null = every field. */
        public ?array $allowedFields = null,
        /** Searched as the system (SearchBuilder::asSystem()): no guard runs, the search log records it as such. Never together with an actor. */
        public bool $system = false,
        /** Explain which word matched where, for the returned page (SearchBuilder::explainMatches()). */
        public bool $explainMatches = false,
        /** The returnable columns the hits carry (SearchBuilder::with()): null = not asked, [] = every returnable column. */
        public ?array $returning = null,
        /** What a search asks of the many fields with `rank: best` (SearchBuilder::manyMatch()). */
        public ManyMatch $manyMatch = ManyMatch::Any,
        /** The many fields manyMatch() names; [] = every one the viewer may search. */
        public array $manyMatchFields = [],
    ) {
        if ($facetValues < 1 || $facetValues > 100) {
            throw new \Fuzzphony\Core\Exception\InvalidQuery(sprintf('Facet values must be between 1 and 100, got %d.', $facetValues));
        }
        if ($limit < 1 || $limit > 1000) {
            throw new \Fuzzphony\Core\Exception\InvalidQuery(sprintf('Limit must be between 1 and 1000, got %d.', $limit));
        }
        if ($offset < 0) {
            throw new \Fuzzphony\Core\Exception\InvalidQuery('Offset must be >= 0.');
        }
    }

    public function withText(string $text): self
    {
        return $this->copy(text: $text);
    }

    public function where(string $filter, mixed $operatorOrValue, mixed $value = null): self
    {
        // where('inStock', true) is shorthand for where('inStock', '=', true)
        if (func_num_args() === 2) {
            return $this->withCondition(new Condition($filter, Operator::Eq, $operatorOrValue));
        }
        $operator = $operatorOrValue instanceof Operator || is_string($operatorOrValue)
            ? Operator::parse($operatorOrValue)
            : throw new \Fuzzphony\Core\Exception\InvalidQuery('The operator must be a string such as "<=" or an Operator case.');

        return $this->withCondition(new Condition($filter, $operator, $value));
    }

    /** @param list<mixed> $values */
    public function whereIn(string $filter, array $values): self
    {
        return $this->withCondition(new Condition($filter, Operator::In, $values));
    }

    public function whereBetween(string $filter, mixed $from, mixed $to): self
    {
        return $this->withCondition(new Condition($filter, Operator::Between, [$from, $to]));
    }

    public function whereNull(string $filter, bool $isNull = true): self
    {
        return $this->withCondition(new Condition($filter, $isNull ? Operator::IsNull : Operator::IsNotNull));
    }

    public function withCondition(Condition $condition): self
    {
        return $this->copy(conditions: [...$this->conditions, $condition]);
    }

    public function forTenant(mixed $value): self
    {
        return $this->copy(tenant: $value);
    }

    public function withActor(\Fuzzphony\Core\Search\Actor $actor): self
    {
        return $this->copy(actor: $actor, system: false);
    }

    /** Searched as the system: no actor (the last of asActor() / asSystem() wins). */
    public function asSystem(): self
    {
        return $this->copy(actor: null, system: true);
    }

    public function explainMatches(bool $explain = true): self
    {
        return $this->copy(explainMatches: $explain);
    }

    /** @param list<string> $fields */
    public function withManyMatch(ManyMatch $mode, array $fields = []): self
    {
        return $this->copy(manyMatch: $mode, manyMatchFields: $fields);
    }

    /** @param list<string> $fields */
    public function returning(array $fields): self
    {
        return $this->copy(returning: $fields);
    }

    public function profile(string $profile): self
    {
        return $this->copy(profile: $profile);
    }

    public function limit(int $limit, int $offset = 0): self
    {
        return $this->copy(limit: $limit, offset: $offset);
    }

    public function page(int $page, int $perPage = 20): self
    {
        return $this->copy(limit: $perPage, offset: max(0, $page - 1) * $perPage);
    }

    public function highlight(string ...$fields): self
    {
        return $this->copy(highlight: array_unique([...$this->highlight, ...$fields]));
    }

    /** @param array<string, mixed> $overrides e.g. ['relevance' => ['min_score' => 0.1], 'typo' => ['mode' => 'always']] */
    public function thresholds(array $overrides): self
    {
        return $this->copy(thresholdOverrides: array_replace_recursive($this->thresholdOverrides, $overrides));
    }

    /** @param array<string, mixed> $overrides e.g. ['fuzzy' => 0.8, 'boost' => 0.1] applied on top of the chosen profile */
    public function ranking(array $overrides): self
    {
        return $this->copy(rankingOverrides: [...$this->rankingOverrides, ...$overrides]);
    }

    /** Counts the values of these filters among the matches (see SearchResult::$facets). */
    public function facets(string ...$filters): self
    {
        return $this->copy(facets: array_unique([...$this->facets, ...$filters]));
    }

    public function facetValues(int $values): self
    {
        return $this->copy(facetValues: $values);
    }

    /** Search-as-you-type: the last word, when the text ends with one, also matches as the beginning of a longer word. */
    public function asYouType(bool $asYouType = true): self
    {
        return $this->copy(asYouType: $asYouType);
    }

    public function exactCounts(bool $exact = true): self
    {
        return $this->copy(exactCounts: $exact);
    }

    /** Keeps this search out of the search log (a refusal is still recorded). */
    public function unlogged(): self
    {
        return $this->copy(log: false);
    }

    /** @param list<string> $fields */
    public function restrictedTo(array $fields): self
    {
        return $this->copy(allowedFields: $fields);
    }

    private function copy(mixed ...$changes): self
    {
        $text = $changes['text'] ?? null;
        $conditions = $changes['conditions'] ?? null;
        $profile = $changes['profile'] ?? null;
        $limit = $changes['limit'] ?? null;
        $offset = $changes['offset'] ?? null;
        $highlight = $changes['highlight'] ?? null;
        $thresholdOverrides = $changes['thresholdOverrides'] ?? null;
        $rankingOverrides = $changes['rankingOverrides'] ?? null;
        $tenant = array_key_exists('tenant', $changes) ? $changes['tenant'] : $this->tenant;
        $facets = $changes['facets'] ?? null;
        $facetValues = $changes['facetValues'] ?? null;
        $exactCounts = $changes['exactCounts'] ?? null;
        $asYouType = $changes['asYouType'] ?? null;
        $actor = array_key_exists('actor', $changes) ? $changes['actor'] : $this->actor;
        $log = $changes['log'] ?? null;
        $allowedFields = array_key_exists('allowedFields', $changes) ? self::stringList($changes['allowedFields']) : $this->allowedFields;
        $system = $changes['system'] ?? null;
        $explainMatches = $changes['explainMatches'] ?? null;
        $returning = array_key_exists('returning', $changes) ? self::stringList($changes['returning']) : $this->returning;
        $manyMatch = $changes['manyMatch'] ?? null;
        $manyMatchFields = $changes['manyMatchFields'] ?? null;

        return new self(
            text: is_string($text) ? $text : $this->text,
            conditions: self::conditionList($conditions) ?? $this->conditions,
            profile: is_string($profile) ? $profile : $this->profile,
            limit: is_int($limit) ? $limit : $this->limit,
            offset: is_int($offset) ? $offset : $this->offset,
            highlight: self::stringList($highlight) ?? $this->highlight,
            thresholdOverrides: self::stringKeyedArray($thresholdOverrides) ?? $this->thresholdOverrides,
            rankingOverrides: self::stringKeyedArray($rankingOverrides) ?? $this->rankingOverrides,
            tenant: $tenant,
            facets: self::stringList($facets) ?? $this->facets,
            facetValues: is_int($facetValues) ? $facetValues : $this->facetValues,
            exactCounts: is_bool($exactCounts) ? $exactCounts : $this->exactCounts,
            asYouType: is_bool($asYouType) ? $asYouType : $this->asYouType,
            actor: $actor instanceof \Fuzzphony\Core\Search\Actor ? $actor : null,
            log: is_bool($log) ? $log : $this->log,
            allowedFields: $allowedFields,
            system: is_bool($system) ? $system : $this->system,
            explainMatches: is_bool($explainMatches) ? $explainMatches : $this->explainMatches,
            returning: $returning,
            manyMatch: $manyMatch instanceof ManyMatch ? $manyMatch : $this->manyMatch,
            manyMatchFields: self::stringList($manyMatchFields) ?? $this->manyMatchFields,
        );
    }

    /** @return list<Condition>|null */
    private static function conditionList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        foreach ($value as $item) {
            if (!$item instanceof Condition) {
                return null;
            }
        }

        return array_values($value);
    }

    /** @return list<string>|null */
    private static function stringList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                return null;
            }
        }

        return array_values($value);
    }

    /** @return array<string, mixed>|null */
    private static function stringKeyedArray(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return null;
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
