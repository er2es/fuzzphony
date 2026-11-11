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

        return $query->manyMatchFields === [] ? $visible : $query->manyMatchFields;
    }
}
