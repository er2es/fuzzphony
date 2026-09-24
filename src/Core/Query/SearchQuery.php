<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query;

use Fuzzphony\Core\Query\Filter\Condition;
use Fuzzphony\Core\Query\Filter\Operator;

/**
 * Immutable search request. Every with*() / where*() call returns a new instance,
 * so a base query can be safely reused and extended.
 */
final readonly class SearchQuery
{
    /**
     * @param list<Condition>      $conditions
     * @param list<string>         $highlight           field names
     * @param array<string, mixed> $thresholdOverrides snake_case keys, see Thresholds::with()
     * @param array<string, mixed> $rankingOverrides   snake_case keys, see RankingProfile::with()
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
    ) {
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
        return $this->copy(highlight: array_values(array_unique([...$this->highlight, ...$fields])));
    }

    /** @param array<string, mixed> $overrides e.g. ['min_score' => 0.1, 'fuzzy_mode' => 'always'] */
    public function thresholds(array $overrides): self
    {
        return $this->copy(thresholdOverrides: [...$this->thresholdOverrides, ...$overrides]);
    }

    /** @param array<string, mixed> $overrides e.g. ['fuzzy' => 0.8, 'boost' => 0.1] applied on top of the chosen profile */
    public function ranking(array $overrides): self
    {
        return $this->copy(rankingOverrides: [...$this->rankingOverrides, ...$overrides]);
    }

    private function copy(mixed ...$changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
