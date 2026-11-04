<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Inspection;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\ManyValues;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal The zero-row probe of the child tables of an index with several values per document (many): each `from` table must exist
 * and have the key, value and id columns the definition names. Nothing is read (LIMIT 0).
 */
final class ManyProbe
{
    /** @throws InvalidDefinition naming the field or filter and what is missing */
    public static function assertColumns(Connection $connection, IndexDefinition $index): void
    {
        $problems = [];
        $checked = [];
        foreach ([...$index->manyFields(), ...$index->manyFilters()] as $one) {
            $many = $one->many;
            if (!$many instanceof ManyValues || isset($checked[$one::class . $one->name])) {
                continue;
            }
            $checked[$one::class . $one->name] = true;
            $what = sprintf('%s "%s"', $one instanceof \Fuzzphony\Core\Definition\FieldDefinition ? 'Field' : 'Filter', $one->name);
            try {
                $connection->execute('SELECT 1 FROM ' . Sql::ident($many->from) . ' LIMIT 0');
            } catch (\Throwable $e) {
                $problems[] = sprintf('%s: the child table "%s" (many.from) cannot be read: %s', $what, $many->from, self::firstLine($e));

                continue;
            }
            foreach (['key' => $many->key, 'column' => $many->column, 'id' => $many->id] as $option => $column) {
                try {
                    $connection->execute(sprintf('SELECT %s FROM %s LIMIT 0', Sql::ident($column), Sql::ident($many->from)));
                } catch (\Throwable) {
                    $problems[] = sprintf('%s: the child table "%s" has no column "%s" (many.%s).', $what, $many->from, $column, $option);
                }
            }
        }
        if ($problems !== []) {
            throw new InvalidDefinition($index->name, $problems);
        }
    }

    private static function firstLine(\Throwable $e): string
    {
        $line = strtok($e->getMessage(), "\n");

        return trim($line !== false ? $line : $e->getMessage());
    }
}
