<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\DefinitionValidator;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal The rows of a sidecar written with another column map (`field_map`, Names::fieldMap()) than the current definition's.
 * Such a row has a per-field column empty whose value its map says another column holds; the readers take the shared column only
 * for a row of the current map; any other row is read from its own per-field columns until a reindex rewrites it.
 */
final readonly class ColumnMap
{
    /** The mark of a map the guard no longer trusts (`!z_name=exact`): never equal to a current map, never trusted again. */
    public const string STALE = '!';

    private const string TAG = DefinitionValidator::DOLLAR_QUOTE_TAG;

    /**
     * In the apply's transaction, before the refresh functions are replaced: when the refresh function in the database writes no
     * `field_map` (an earlier release's, after a downgrade), the rows it rewrote kept the map this release gave them while their
     * shared columns may hold another definition's fields. No row says which, so every map is marked stale: such a row is read from
     * its own columns (the earlier release filled those it rewrote; the others miss field-scoped and restricted searches, never a
     * hidden field) and stays so until a reindex writes it again. Costs one catalog lookup otherwise.
     */
    public static function guard(IndexDefinition $index, Names $names): Statement
    {
        $table = $names->sidecar($index);
        $function = Sql::string(sprintf('%s(%s[])', $names->refreshFunction($index), Types::id($index->idType)));

        return new Statement(
            sprintf(
                "DO %1\$s BEGIN IF position('%4\$s' IN coalesce((SELECT prosrc FROM pg_proc WHERE oid = to_regprocedure(%2\$s)), '%4\$s')) = 0 AND EXISTS (SELECT 1 FROM %3\$s WHERE %4\$s IS NOT NULL AND left(%4\$s, 1) <> '%5\$s') THEN UPDATE %3\$s SET %4\$s = '%5\$s' || %4\$s WHERE %4\$s IS NOT NULL AND left(%4\$s, 1) <> '%5\$s'; END IF; END %1\$s",
                self::TAG,
                $function,
                $table,
                PostgresSchemaGenerator::FIELD_MAP_COLUMN,
                self::STALE,
            ),
            sprintf('Distrust the column maps of "%s" when the refresh function of an earlier release wrote the table (after a downgrade)', $index->name),
        );
    }

    /** How many rows have a map the guard marked stale (they wait for a reindex). Reads the whole sidecar. */
    public static function stale(Connection $connection, Names $names, IndexDefinition $index): int
    {
        $exists = $connection->fetchValue(
            'SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass(:table) AND attname = :column AND NOT attisdropped',
            ['table' => $names->sidecar($index), 'column' => PostgresSchemaGenerator::FIELD_MAP_COLUMN],
        );

        return $exists === null || $exists === false ? 0 : Coerce::int($connection->fetchValue(sprintf(
            "SELECT count(*) FROM %s WHERE left(%s, 1) = '%s'",
            $names->sidecar($index),
            PostgresSchemaGenerator::FIELD_MAP_COLUMN,
            self::STALE,
        )));
    }
}
