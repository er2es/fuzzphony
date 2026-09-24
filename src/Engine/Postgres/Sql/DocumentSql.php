<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Sql;

use Fuzzphony\Core\Definition\IndexDefinition;

/**
 * @internal The canonical "document" SELECT with stable aliases:
 *   fz_id, fld_<field>, flt_<filter>, fz_boost, fz_recency
 * The source (table or user query) is wrapped as a subquery so the planner can push
 * "fz_id = ANY(...)" down into it.
 */
final class DocumentSql
{
    public static function select(IndexDefinition $index): string
    {
        $columns = ['d.' . Sql::ident($index->source->idColumn) . ' AS fz_id'];
        foreach ($index->fields as $field) {
            $columns[] = 'd.' . Sql::ident($field->column()) . ' AS ' . Sql::ident('fld_' . $field->name);
        }
        foreach ($index->filters as $filter) {
            $columns[] = 'd.' . Sql::ident($filter->column()) . ' AS ' . Sql::ident('flt_' . $filter->name);
        }
        if ($index->boostColumn !== null) {
            $columns[] = 'd.' . Sql::ident($index->boostColumn) . ' AS fz_boost';
        }
        if ($index->recencyColumn !== null) {
            $columns[] = 'd.' . Sql::ident($index->recencyColumn) . ' AS fz_recency';
        }

        return sprintf("SELECT %s\nFROM (%s) AS d", implode(', ', $columns), self::raw($index));
    }

    /** The untouched source: the user's query or the whole table. */
    public static function raw(IndexDefinition $index): string
    {
        return $index->source->query ?? 'SELECT * FROM ' . Sql::ident((string) $index->source->table);
    }
}
