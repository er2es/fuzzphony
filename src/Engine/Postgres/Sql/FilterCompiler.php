<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Sql;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Query\Filter\Condition;
use Fuzzphony\Core\Query\Filter\Operator;

/** @internal Compiles where() conditions against the typed filter columns of the sidecar table. */
final class FilterCompiler
{
    private const int MAX_IN_VALUES = 1000;

    public function __construct(private readonly IndexDefinition $index) {}

    /** @param list<Condition> $conditions */
    public function compile(array $conditions, ParameterBag $params, string $alias = 's'): string
    {
        $sql = [];
        foreach ($conditions as $condition) {
            $sql[] = $this->condition($condition, $params, $alias);
        }

        return $sql === [] ? 'TRUE' : implode(' AND ', $sql);
    }

    /** Validates conditions without generating SQL (fail fast in the builder). */
    public function validate(Condition ...$conditions): void
    {
        $this->compile(array_values($conditions), new ParameterBag());
    }

    public static function column(string $filter): string
    {
        return Sql::ident('f_' . $filter);
    }

    private function condition(Condition $c, ParameterBag $params, string $alias): string
    {
        $filter = $this->index->filter($c->filter);
        $column = $alias . '.' . self::column($filter->name);
        $bind = fn(mixed $value): string => $params->add($filter->type->normalize($value, $filter->name));
        if ($filter->many !== null) {
            return $this->many($c, $column, $bind);
        }

        return match ($c->operator) {
            Operator::IsNull => $column . ' IS NULL',
            Operator::IsNotNull => $column . ' IS NOT NULL',
            Operator::Eq => $c->value === null ? $column . ' IS NULL' : $column . ' = ' . $bind($c->value),
            Operator::Neq => $c->value === null ? $column . ' IS NOT NULL' : $column . ' IS DISTINCT FROM ' . $bind($c->value),
            Operator::Lt => $column . ' < ' . $bind($c->value),
            Operator::Lte => $column . ' <= ' . $bind($c->value),
            Operator::Gt => $column . ' > ' . $bind($c->value),
            Operator::Gte => $column . ' >= ' . $bind($c->value),
            Operator::Between => $this->between($c, $column, $bind),
            Operator::In, Operator::NotIn => $this->in($c, $column, $bind),
        };
    }

    /**
     * A filter whose values come from a child table: the column holds the array of the document's distinct values, so "any of them"
     * is containment (`@>` for one value, `&&` for a list) and the negations are its NOT. Ranges have no meaning for it.
     *
     * @param \Closure(mixed): string $bind
     */
    private function many(Condition $c, string $column, \Closure $bind): string
    {
        $none = sprintf('coalesce(cardinality(%s), 0) = 0', $column);
        if ($c->operator === Operator::NotIn || ($c->operator === Operator::Neq && $c->value !== null)) {
            // only the first `limit` values of a document are stored: a document whose value lies past it would pass the negation (fail open)
            throw new InvalidQuery(sprintf('Filter "%s" takes several values per document and stores only the first ones, so %s could let through a document that has the value. Use a positive filter (=, IN) or a single-valued filter for exclusions.', $c->filter, $c->operator === Operator::Neq ? '!=' : 'NOT IN'));
        }
        $one = fn(): string => sprintf('coalesce(%s @> ARRAY[%s]::text[], false)', $column, $bind($c->value));

        return match ($c->operator) {
            Operator::IsNull => $none,
            Operator::IsNotNull => 'NOT (' . $none . ')',
            Operator::Eq => $c->value === null ? $none : $one(),
            Operator::Neq => $c->value === null ? 'NOT (' . $none . ')' : 'NOT ' . $one(),
            Operator::In, Operator::NotIn => $this->manyIn($c, $column, $bind),
            default => throw new InvalidQuery(sprintf('Filter "%s" takes several values per document: it supports =, IN and null checks (any value matches), not %s.', $c->filter, strtoupper($c->operator->value))),
        };
    }

    /** @param \Closure(mixed): string $bind */
    private function manyIn(Condition $c, string $column, \Closure $bind): string
    {
        if (!is_array($c->value)) {
            throw new InvalidQuery(sprintf('Filter "%s" %s expects a list of values.', $c->filter, strtoupper($c->operator->value)));
        }
        if (count($c->value) > self::MAX_IN_VALUES) {
            throw new InvalidQuery(sprintf('Filter "%s" accepts at most %d values in IN().', $c->filter, self::MAX_IN_VALUES));
        }
        if ($c->value === []) {
            return $c->operator === Operator::In ? 'FALSE' : 'TRUE';
        }
        $any = sprintf('coalesce(%s && ARRAY[%s]::text[], false)', $column, implode(', ', array_map($bind, array_values($c->value))));

        return $c->operator === Operator::In ? $any : 'NOT ' . $any;
    }

    /** @param \Closure(mixed): string $bind */
    private function between(Condition $c, string $column, \Closure $bind): string
    {
        if (!is_array($c->value) || count($c->value) !== 2 || !array_is_list($c->value)) {
            throw new InvalidQuery(sprintf('Filter "%s" BETWEEN expects exactly two values [from, to].', $c->filter));
        }

        return sprintf('%s BETWEEN %s AND %s', $column, $bind($c->value[0]), $bind($c->value[1]));
    }

    /** @param \Closure(mixed): string $bind */
    private function in(Condition $c, string $column, \Closure $bind): string
    {
        if (!is_array($c->value)) {
            throw new InvalidQuery(sprintf('Filter "%s" %s expects a list of values.', $c->filter, strtoupper($c->operator->value)));
        }
        if (count($c->value) > self::MAX_IN_VALUES) {
            throw new InvalidQuery(sprintf('Filter "%s" accepts at most %d values in IN().', $c->filter, self::MAX_IN_VALUES));
        }
        if ($c->value === []) {
            return $c->operator === Operator::In ? 'FALSE' : 'TRUE';
        }
        $placeholders = implode(', ', array_map($bind, array_values($c->value)));

        return sprintf('%s %s (%s)', $column, $c->operator === Operator::In ? 'IN' : 'NOT IN', $placeholders);
    }
}
