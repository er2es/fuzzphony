<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Inspection;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Sql\DocumentSql;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal The zero-row probe of a multi-source index: each source's SELECT is described (a temporary view, no row is read) and
 * must return its id column and every field, filter, boost and recency column the index declares, under the common names.
 */
final class SourceProbe
{
    /** @throws InvalidDefinition naming the source and the column that is missing */
    public static function assertColumns(Connection $connection, IndexDefinition $index): void
    {
        $problems = [];
        foreach ($index->sources as $one) {
            $part = $index->forSource($one);
            $view = 'fuzzphony_probe_' . bin2hex(random_bytes(4));
            try {
                $connection->execute(sprintf('CREATE TEMPORARY VIEW %s AS %s', Sql::ident($view), DocumentSql::raw($part)));
                $columns = array_map(Coerce::str(...), array_column($connection->fetchAll(
                    'SELECT attname FROM pg_attribute WHERE attrelid = to_regclass(:view) AND attnum > 0 AND NOT attisdropped',
                    ['view' => 'pg_temp.' . $view],
                ), 'attname'));
            } catch (\Throwable $e) {
                $line = strtok($e->getMessage(), "\n");
                $problems[] = sprintf('Source "%s" cannot be queried: %s', $one->name, trim($line !== false ? $line : $e->getMessage()));

                continue;
            } finally {
                try {
                    $connection->execute(sprintf('DROP VIEW IF EXISTS %s', Sql::ident($view)));
                } catch (\Throwable) {
                }
            }
            $needed = ['id column' => $one->source->idColumn];
            foreach ($index->fields as $field) {
                $needed['field "' . $field->name . '"'] = $field->column();
            }
            foreach ($index->filters as $filter) {
                $needed['filter "' . $filter->name . '"'] = $filter->column();
            }
            if ($index->boostColumn !== null) {
                $needed['boost'] = $index->boostColumn;
            }
            if ($index->recencyColumn !== null) {
                $needed['recency'] = $index->recencyColumn;
            }
            foreach ($needed as $what => $column) {
                if (!in_array($column, $columns, true)) {
                    $problems[] = sprintf('Source "%s" does not return column "%s" (%s): alias your own column to it in the source query. It returns: %s.', $one->name, $column, $what, implode(', ', $columns));
                }
            }
        }
        if ($problems !== []) {
            throw new InvalidDefinition($index->name, $problems);
        }
    }
}
