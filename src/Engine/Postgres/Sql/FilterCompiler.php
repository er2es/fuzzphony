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
