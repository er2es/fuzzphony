<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Inspection;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Sql\DocumentSql;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal "Tables read but not watched": the tables a source query reads, found WITHOUT parsing SQL. The query is put into a
 * temporary view (no row is read) and PostgreSQL's own dependency catalog (pg_rewrite + pg_depend) says which relations the view
 * depends on; views among them are followed down to the base tables. A table the query reads that no watch covers is a change the
 * index never hears of.
 */
final class WatchCoverage
{
    public function __construct(
        private readonly Connection $connection,
        /** Fuzzphony's own schema: its tables are not the application's data. */
        private readonly string $schema,
    ) {}

    /**
     * One check per unwatched table, or one OK check; empty when there is nothing to check (manual sync).
     *
     * @param IndexDefinition $part   a single-source index (a source of a multi-source one as `forSource()`)
     * @param string|null     $source the source's name, for a multi-source index
     *
     * @return list<Check>
     */
    public function check(IndexDefinition $part, ?string $source = null): array
    {
        if (!$part->sync->usesTriggers()) {
            return [];
        }
        $view = 'fuzzphony_probe_' . bin2hex(random_bytes(4));
        try {
            $this->connection->execute(sprintf('CREATE TEMPORARY VIEW %s AS %s', Sql::ident($view), DocumentSql::raw($part)));
            $read = $this->connection->fetchAll(
                <<<'SQL'
                    WITH RECURSIVE r(rel) AS (
                        SELECT d.refobjid FROM pg_rewrite w
                        JOIN pg_depend d ON d.classid = 'pg_rewrite'::regclass AND d.objid = w.oid AND d.refclassid = 'pg_class'::regclass AND d.refobjid <> w.ev_class
                        WHERE w.ev_class = to_regclass(:view)
                      UNION
                        SELECT d.refobjid FROM r
                        JOIN pg_class v ON v.oid = r.rel AND v.relkind = 'v'
                        JOIN pg_rewrite w ON w.ev_class = v.oid
                        JOIN pg_depend d ON d.classid = 'pg_rewrite'::regclass AND d.objid = w.oid AND d.refclassid = 'pg_class'::regclass AND d.refobjid <> w.ev_class
                    )
                    SELECT DISTINCT c.oid::bigint AS oid, n.nspname, c.relname,
                           ARRAY(SELECT a::bigint FROM pg_partition_ancestors(c.oid) AS a WHERE a <> c.oid) AS ancestors
                    FROM r JOIN pg_class c ON c.oid = r.rel JOIN pg_namespace n ON n.oid = c.relnamespace
                    WHERE c.relkind IN ('r', 'p', 'm', 'f')
                      AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg\_toast%'
                      AND NOT (n.nspname = :schema AND c.relname LIKE 'fuzzphony\_%')
                    ORDER BY n.nspname, c.relname
                    SQL,
                ['view' => 'pg_temp.' . $view, 'schema' => $this->schema],
            );
            $watched = [];
            foreach ($part->effectiveWatches() as $watch) {
                $oid = $this->connection->fetchValue('SELECT to_regclass(:table)::oid::bigint', ['table' => Sql::ident($watch->table)]);
                if ($oid !== null) {
                    $watched[Coerce::int($oid)] = true;
                }
            }
        } catch (\Throwable $e) {
            $line = strtok($e->getMessage(), "\n");

            return [Check::ok('Watches', sprintf('not checked: %s', trim($line !== false ? $line : $e->getMessage())))];
        } finally {
            try {
                $this->connection->execute(sprintf('DROP VIEW IF EXISTS %s', Sql::ident($view)));
            } catch (\Throwable) {
            }
        }

        $checks = [];
        foreach ($read as $row) {
            if (isset($watched[Coerce::int($row['oid'])])) {
                continue;
            }
            // a partition is covered by its watched parent
            $covered = false;
            foreach (self::oids($row['ancestors']) as $ancestor) {
                $covered = $covered || isset($watched[$ancestor]);
            }
            if ($covered) {
                continue;
            }
            $checks[] = $this->unwatched($part, $source, Coerce::str($row['nspname']), Coerce::str($row['relname']));
        }

        return $checks !== [] ? $checks : [Check::ok('Watches', sprintf('%d table(s) read by the source, all watched', count($read)))];
    }

    private function unwatched(IndexDefinition $part, ?string $source, string $schema, string $table): Check
    {
        $shown = $schema === 'public' ? $table : $schema . '.' . $table;
        $own = $part->source->table ?? '<source-table>';

        return Check::warning(
            'Watches',
            sprintf(
                '%sthe source query reads "%s" but no watch covers it: a change there does not update the index.',
                $source !== null ? sprintf('Source "%s": ', $source) : '',
                $shown,
            ),
            sprintf(
                "add a watch (adapt the ids query: it must return the ids of the documents a changed row of \"%s\" affects; :id is that row's key): watch: { %s: 'SELECT %s FROM %s WHERE %s = :id' }   or set sync: manual",
                $shown,
                $shown,
                $part->source->idColumn,
                $own,
                '<fk>',
            ),
        );
    }

    /** @return list<int> */
    private static function oids(mixed $array): array
    {
        $text = trim(Coerce::str($array), '{}');

        return $text === '' ? [] : array_map(static fn(string $o): int => (int) $o, explode(',', $text));
    }
}
