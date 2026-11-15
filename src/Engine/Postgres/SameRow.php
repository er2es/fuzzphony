<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Definition\FilterDefinition;
use Fuzzphony\Core\Definition\IndexDefinition;

/**
 * @internal The many filters declared `same_row_as: <field>`: their value is stored on each value row of the field (Names::sameRowColumnName()),
 * and under `manyMatch('single')` a positive condition on them holds for the same child row whose value holds the field's words.
 */
final class SameRow
{
    /** @return list<FilterDefinition> the filters with `same_row_as` that name a many field of the index (a valid definition has no other) */
    public static function filters(IndexDefinition $index): array
    {
        return array_values(array_filter(
            $index->filters,
            static fn(FilterDefinition $f): bool => $f->many?->sameRowAs !== null && $index->field($f->many->sameRowAs)?->many !== null,
        ));
    }

    /**
     * The coupled filters of a search: those whose field is one the single-value check reads for THIS viewer (ManyMatchFields::resolve(),
     * which leaves out a field the viewer may not see). Empty outside `manyMatch('single')`.
     *
     * @param list<string> $single
     *
     * @return array<string, string> filter name => field name
     */
    public static function coupled(IndexDefinition $index, array $single): array
    {
        $coupled = [];
        foreach (self::filters($index) as $filter) {
            $field = $filter->many?->sameRowAs;
            if ($field !== null && in_array($field, $single, true)) {
                $coupled[$filter->name] = $field;
            }
        }

        return $coupled;
    }
}
