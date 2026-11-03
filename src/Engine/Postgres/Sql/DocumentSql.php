<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Sql;

use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Engine\Postgres\Schema\Types;

/**
 * @internal The canonical "document" SELECT with stable aliases:
 *   fz_id, fld_<field>, flt_<filter>, fz_boost, fz_recency
 * The source (table or user query) is wrapped as a subquery so the planner can push
 * "fz_id = ANY(...)" down into it.
 */
final class DocumentSql
{
    /**
     * @param string|null $ids SQL of a text[] with stored keys: a multi-source index pushes each kind's part down into its own
     *                         branch (`WHERE id = ANY(...)` on the source's key), so a lookup by ids never scans another source.
     *                         Ignored for a single-source index (the caller filters `fz_id` itself, as before).
     */
    public static function select(IndexDefinition $index, ?string $ids = null): string
    {
        if ($index->isMultiSource()) {
            return self::multi($index, $ids);
        }
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

    /**
     * The documents of a multi-source index: one branch per source, each aliasing the source's columns to the common fields, with the
     * stored key `<kind>:<id>` as fz_id plus fz_kind and fz_src (the sidecar's kind and src_id columns). Columns are cast to the types
     * the branches must share (fields to text, filters to their type).
     */
    private static function multi(IndexDefinition $index, ?string $ids): string
    {
        $branches = [];
        foreach ($index->activeSources() as $one) {
            $id = 'd.' . Sql::ident($one->source->idColumn);
            $columns = [
                sprintf('%s || %s::text AS fz_id', Sql::string($one->name . ':'), $id),
                Sql::string($one->name) . '::text AS fz_kind',
                $id . '::text AS fz_src',
            ];
            foreach ($index->fields as $field) {
                $columns[] = 'd.' . Sql::ident($field->column()) . '::text AS ' . Sql::ident('fld_' . $field->name);
            }
            foreach ($index->filters as $filter) {
                $columns[] = 'd.' . Sql::ident($filter->column()) . '::' . Types::filter($filter->type) . ' AS ' . Sql::ident('flt_' . $filter->name);
            }
            if ($index->boostColumn !== null) {
                $columns[] = 'd.' . Sql::ident($index->boostColumn) . '::double precision AS fz_boost';
            }
            if ($index->recencyColumn !== null) {
                $columns[] = 'd.' . Sql::ident($index->recencyColumn) . '::timestamptz AS fz_recency';
            }
            $where = '';
            if ($ids !== null) {
                $prefix = $one->name . ':';
                $cast = match ($one->idType) {
                    IdType::Int => '::bigint',
                    IdType::Uuid => '::uuid',
                    IdType::String => '',
                };
                $where = sprintf(
                    "
WHERE %s%s = ANY (ARRAY(SELECT substr(u.k, %d)%s FROM unnest(%s) AS u(k) WHERE starts_with(u.k, %s)))",
                    $id,
                    $one->idType === IdType::String ? '::text' : '',
                    strlen($prefix) + 1,
                    $cast,
                    $ids,
                    Sql::string($prefix),
                );
            }
            $branches[] = sprintf("SELECT %s
FROM (%s) AS d%s", implode(', ', $columns), $one->source->query ?? 'SELECT * FROM ' . Sql::ident((string) $one->source->table), $where);
        }

        return implode("
UNION ALL
", array_map(static fn(string $b): string => '(' . $b . ')', $branches));
    }

    /** The untouched source: the user's query or the whole table. */
    public static function raw(IndexDefinition $index): string
    {
        return $index->source->query ?? 'SELECT * FROM ' . Sql::ident((string) $index->source->table);
    }
}
