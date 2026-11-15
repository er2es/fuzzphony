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
 * for a row of the current map, so the row's pairs are copied back (the backfill: `t_name = tsv`, valid by the row's own map) and
 * its map set to NULL (every per-field column filled, like a row of 0.12.3). After it a row has the current map or none.
 */
final readonly class ColumnMap
{
    /** Rows per UPDATE of the backfill; each batch commits, so no lock is held for long and an interrupted apply resumes. */
    public const int BATCH = 20_000;

    /** The description of the backfill statement (SchemaCommand asks before it runs it). */
    public const string BACKFILL = 'Backfill the per-field columns of rows written with another column map';

    /** The description of the VACUUM that follows a backfill which updated rows (SchemaCommand reports the growth around it). */
    public const string VACUUM = 'Vacuum the sidecar after the backfill updated rows';

    /**
     * The session setting the backfill leaves the sidecar's name in when it updated rows (empty otherwise): the VACUUM after it runs
     * only then. Session-level, so the next statement of the apply's connection reads it (a pooler that hands that statement to
     * another session skips the VACUUM: autovacuum then does the work later, nothing is wrong).
     */
    public const string BACKFILLED = 'fuzzphony.backfilled';

    /** The mark of a map the guard no longer trusts (`!z_name=exact`): never equal to a current map, never backfilled. */
    public const string STALE = '!';

    private const string TAG = DefinitionValidator::DOLLAR_QUOTE_TAG;

    /**
     * In the apply's transaction, before the refresh functions are replaced: when the refresh function in the database writes no
     * `field_map` (an earlier release's, after a downgrade), the rows it rewrote kept the map this release gave them while their
     * shared columns may hold another definition's fields. No row says which, so every map is marked stale: such a row is read from
     * its own columns (the earlier release filled those it rewrote; the others miss field-scoped and restricted searches, never a
     * hidden field) and is never backfilled, until a reindex writes it again. Costs one catalog lookup otherwise.
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

    /**
     * The backfill of one index: a DO block that commits after each batch, so it runs outside a transaction (like CREATE INDEX
     * CONCURRENTLY). Idempotent: a second run finds nothing. It reads the sidecar once to find the other maps (SELECT DISTINCT),
     * then walks each map's rows in id order, $batch at a time. The map is checked on the UPDATE's own table too: a row that a
     * refresh rewrote (under the current map) while the batch waited on its lock is re-checked on its new version and skipped, so
     * a column is only ever copied from the row version that map was written with. The walk follows the ids the batch selected,
     * not the rows it updated, so a skipped or deleted row does not end it early.
     */
    public static function backfill(IndexDefinition $index, Names $names, int $batch = self::BATCH): Statement
    {
        $table = $names->sidecar($index);
        $literal = Sql::string($table);
        $inner = str_replace("'", "''", $table);

        return new Statement(
            sprintf(
                <<<'SQL'
                    DO %1$s
                    DECLARE
                        m text;
                        e text;
                        sets text;
                        last text;
                        n bigint;
                        k bigint;
                        done bigint := 0;
                    BEGIN
                        PERFORM set_config('%7$s', '', false);
                        IF NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass(%2$s) AND attname = '%5$s' AND NOT attisdropped) THEN
                            RETURN;
                        END IF;
                        FOR m IN %6$s LOOP
                            sets := '';
                            FOREACH e IN ARRAY string_to_array(m, ',') LOOP
                                IF split_part(e, '=', 2) IN ('tsv', 'fz', 'exact') AND EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass(%2$s) AND attname = split_part(e, '=', 1) AND NOT attisdropped) THEN
                                    sets := sets || format('%%I = t.%%I, ', split_part(e, '=', 1), split_part(e, '=', 2));
                                END IF;
                            END LOOP;
                            last := NULL;
                            LOOP
                                EXECUTE format(
                                    'WITH b AS (SELECT id FROM %3$s WHERE %5$s = %%1$L%%2$s ORDER BY id LIMIT %4$d), u AS (UPDATE %3$s AS t SET %%3$s %5$s = NULL FROM b WHERE t.id = b.id AND t.%5$s = %%1$L RETURNING 1) SELECT (SELECT id::text FROM b ORDER BY id DESC LIMIT 1), (SELECT count(*) FROM b), (SELECT count(*) FROM u)',
                                    m, CASE WHEN last IS NULL THEN '' ELSE format(' AND id > %%L', last) END, sets
                                ) INTO last, n, k;
                                done := done + k;
                                COMMIT;
                                EXIT WHEN n < %4$d;
                            END LOOP;
                        END LOOP;
                        IF done > 0 THEN
                            PERFORM set_config('%7$s', %2$s, false);
                        END IF;
                    END %1$s
                    SQL,
                self::TAG,
                $literal,
                $inner,
                $batch,
                PostgresSchemaGenerator::FIELD_MAP_COLUMN,
                self::otherMaps($index, $names, 'DISTINCT ' . PostgresSchemaGenerator::FIELD_MAP_COLUMN, ''),
                self::BACKFILLED,
            ),
            sprintf('%s ("%s"; %d rows per batch, outside a transaction)', self::BACKFILL, $index->name, $batch),
            transactional: false,
        );
    }

    /**
     * Right after the backfill, when it updated rows: `VACUUM (ANALYZE)` of the sidecar. Every backfilled row left its old version
     * behind (a new version larger than the page's free space: no HOT update), so the table grew by about its own size; the VACUUM
     * makes that space reusable at once (online: SHARE UPDATE EXCLUSIVE, searches and writes go on) and gives the planner the new
     * row sizes. It does not shrink the file: fuzzphony:reindex (the shadow rebuild) does that online, SchemaCommand says so.
     * VACUUM cannot run inside a transaction or a DO block, so it is a statement of its own, run once when the backfill set
     * BACKFILLED to this table (the `forEach` query returns one row then, the table's name, none otherwise); a dumped migration only
     * names it. A failed VACUUM is a warning that names the table and the statement to run by hand: a second apply would not run it (the
     * backfill finds nothing left, so the setting stays empty).
     */
    public static function vacuum(IndexDefinition $index, Names $names): Statement
    {
        $table = $names->sidecar($index);

        return new Statement(
            'VACUUM (ANALYZE) ' . $table,
            sprintf('%s ("%s")', self::VACUUM, $index->name),
            transactional: false,
            forEach: sprintf("SELECT %2\$s AS name WHERE current_setting('%1\$s', true) = %2\$s", self::BACKFILLED, Sql::string($table)),
        );
    }

    /**
     * The rows that wait for the backfill, by map: map => rows. Empty when the sidecar or its `field_map` column is not there yet
     * (the apply adds it) or every row has the current map or none. Reads the whole sidecar.
     *
     * @return array<string, int>
     */
    public static function pending(Connection $connection, Names $names, IndexDefinition $index): array
    {
        $exists = $connection->fetchValue(
            'SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass(:table) AND attname = :column AND NOT attisdropped',
            ['table' => $names->sidecar($index), 'column' => PostgresSchemaGenerator::FIELD_MAP_COLUMN],
        );
        if ($exists === null || $exists === false) {
            return [];
        }
        $pending = [];
        foreach ($connection->fetchAll(self::otherMaps($index, $names, PostgresSchemaGenerator::FIELD_MAP_COLUMN . ' AS map, count(*) AS n', ' GROUP BY 1 ORDER BY 1')) as $row) {
            $pending[Coerce::str($row['map'])] = Coerce::int($row['n']);
        }

        return $pending;
    }

    /** SELECT $what over the rows of another map than the current one (not a stale one: see guard()). */
    private static function otherMaps(IndexDefinition $index, Names $names, string $what, string $tail): string
    {
        $current = $names->fieldMap($index);

        return sprintf(
            "SELECT %s FROM %s WHERE %s IS NOT NULL AND left(%3\$s, 1) <> '%s'%s%s",
            $what,
            $names->sidecar($index),
            PostgresSchemaGenerator::FIELD_MAP_COLUMN,
            self::STALE,
            $current === null ? '' : sprintf(' AND %s <> %s', PostgresSchemaGenerator::FIELD_MAP_COLUMN, Sql::string($current)),
            $tail,
        );
    }
}
