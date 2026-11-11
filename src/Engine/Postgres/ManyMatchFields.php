<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Query\ManyMatch;
use Fuzzphony\Core\Query\SearchQuery;

/**
 * @internal The many fields (rank: best) a search's manyMatch() applies to for THIS viewer: the ones it names, or every one the viewer
 * may search. A name the viewer may not see is refused exactly like an unknown one, listing only the visible names.
 */
final class ManyMatchFields
{
    /** The most fields one single-value check reads together (their values' cross product). */
    public const int MAX_FIELDS = 3;

    /**
     * @return list<string> empty when the mode is "any" or the viewer sees no field with rank: best (the search is then the default one)
     *
     * @throws InvalidQuery
     */
    public static function resolve(IndexDefinition $index, SearchQuery $query): array
    {
        if ($query->manyMatch === ManyMatch::Any) {
            return [];
        }
        $visible = [];
        foreach ($index->manyBestFields() as $field) {
            if ($query->allowedFields === null || in_array($field->name, $query->allowedFields, true)) {
                $visible[] = $field->name;
            }
        }
        foreach ($query->manyMatchFields as $name) {
            if (!in_array($name, $visible, true)) {
                throw new InvalidQuery(sprintf('manyMatch(): field "%s" is not a many field with rank: best. Fields with it: %s.', $name, $visible === [] ? '(none)' : implode(', ', $visible)));
            }
        }

        $fields = $query->manyMatchFields === [] ? $visible : $query->manyMatchFields;
        if (count($fields) > self::MAX_FIELDS) {
            // the single-value check reads every combination of the fields' matching values: bounded by the number of fields
            throw new InvalidQuery(sprintf('manyMatch(): at most %d fields can be checked together, %d would be (%s); name the ones that must hold the words in one value: manyMatch(\'single\', ...).', self::MAX_FIELDS, count($fields), implode(', ', $fields)));
        }

        return $fields;
    }
}
