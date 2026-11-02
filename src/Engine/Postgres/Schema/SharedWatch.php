<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

use Fuzzphony\Core\Definition\DefinitionValidator;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal The shared trigger of a watched table and the shared change log (queue sync with statement-level triggers).
 *
 * One set of triggers per watched TABLE, whatever the number of indexes watching it, appends the key of every changed row
 * (and, for an UPDATE, which of the watched columns changed) to one log. Each index keeps its own position in the log
 * (`fuzzphony_watches.cur_xid`) and its drain function turns the entries after that position into queue rows: it maps a key to
 * document ids with the index's own watch query, so the write itself costs one INSERT whatever the number of indexes.
 *
 * An entry is read once its transaction is over: the position is a transaction id and an index reads the entries below the
 * oldest transaction that is still running, so a transaction that commits late can never be skipped. The trigger is rebuilt from
 * the watches (fuzzphony_watch_install()) by every apply and every drop, and removed when the last watch of the table is gone.
 */
final readonly class SharedWatch
{
    private const string TAG = DefinitionValidator::DOLLAR_QUOTE_TAG;

    public function __construct(private Names $names) {}

    /** Whether the index's changes go through the shared trigger: queue sync at statement level (the defaults). */
    public static function applies(IndexDefinition $index): bool
    {
        return $index->sync === SyncMode::Queue && $index->triggerLevel === TriggerLevel::Statement;
    }

    /** @return list<Statement> the shared log, the watches and the function that builds the shared trigger */
    public function shared(): array
    {
        return [
            new Statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s (\n    tbl text NOT NULL,\n    xid bigint NOT NULL,\n    at timestamptz NOT NULL DEFAULT now(),\n    op text NOT NULL,\n    key_column text NOT NULL,\n    key text,\n    chg jsonb\n)",
                $this->names->watchLog(),
            ), 'Change log shared by all indexes: the keys of the rows that changed in a watched table'),
            new Statement(sprintf('CREATE INDEX IF NOT EXISTS %s ON %s (tbl, xid)', Sql::ident($this->names->watchLogIndexName()), $this->names->watchLog()), 'Change log by table and transaction'),
            new Statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s (\n    index_name text NOT NULL,\n    watch_no integer NOT NULL,\n    tbl text NOT NULL,\n    fn text NOT NULL,\n    key_column text NOT NULL,\n    columns jsonb,\n    cur_xid bigint NOT NULL,\n    PRIMARY KEY (index_name, watch_no)\n)",
                $this->names->watches(),
            ), 'Which index watches which table, and how far in the change log it has read'),
            new Statement(sprintf('CREATE INDEX IF NOT EXISTS %s ON %s (tbl)', Sql::ident($this->names->watchLogIndexName() . '_w'), $this->names->watches()), 'Watches by table'),
            new Statement($this->installFunction(), 'Builds (or removes) the shared trigger of a watched table from the watches'),
        ];
    }

    /**
     * Records the index's watches and rebuilds the shared trigger of every table it watches, or watched until now.
     *
     * @param callable(IndexDefinition, Watch): ?list<string> $columns
     */
    public function register(IndexDefinition $index, callable $columns): Statement
    {
        $name = Sql::string($index->name);
        $reg = $this->names->watches();
        $rows = [];
        $new = [];
        if (self::applies($index)) {
            foreach ($index->effectiveWatches() as $no => $watch) {
                $cols = $columns($index, $watch);
                $tbl = Sql::string(Sql::ident($watch->table));
                $fn = Sql::string($this->names->watchFunctionName($watch->table));
                $rows[] = sprintf(
                    '(%s, %d, %s, %s, %s, %s, pg_snapshot_xmin(pg_current_snapshot())::text::bigint)',
                    $name,
                    $no,
                    $tbl,
                    $fn,
                    Sql::string($watch->keyColumn),
                    $cols === null ? 'NULL::jsonb' : 'to_jsonb(ARRAY[' . implode(', ', array_map(Sql::string(...), $cols)) . ']::text[])',
                );
                $new[] = sprintf('jsonb_build_array(%s, %s)', $tbl, $fn);
            }
        }
        $upsert = $rows === [] ? '' : sprintf(
            "INSERT INTO %1\$s AS w (index_name, watch_no, tbl, fn, key_column, columns, cur_xid) VALUES\n        %2\$s\n        ON CONFLICT (index_name, watch_no) DO UPDATE SET fn = EXCLUDED.fn, key_column = EXCLUDED.key_column, columns = EXCLUDED.columns,\n            cur_xid = CASE WHEN w.tbl = EXCLUDED.tbl THEN w.cur_xid ELSE EXCLUDED.cur_xid END, tbl = EXCLUDED.tbl;",
            $reg,
            implode(",\n        ", $rows),
        );

        return new Statement(sprintf(
            <<<'SQL'
                DO %1$s
                DECLARE
                    r record;
                    v_tables jsonb := jsonb_build_array(%4$s) || coalesce((SELECT jsonb_agg(DISTINCT jsonb_build_array(tbl, fn)) FROM %2$s WHERE index_name = %3$s), '[]'::jsonb);
                BEGIN
                    DELETE FROM %2$s WHERE index_name = %3$s AND watch_no >= %5$d;
                    %6$s
                    FOR r IN SELECT e->>0 AS tbl, e->>1 AS fn FROM jsonb_array_elements(v_tables) WITH ORDINALITY AS t(e, n) GROUP BY 1, 2 ORDER BY min(n) LOOP
                        PERFORM %7$s(r.tbl, r.fn);
                    END LOOP;
                END
                %1$s
                SQL,
            self::TAG,
            $reg,
            $name,
            $new === [] ? '' : implode(', ', $new),
            count($rows),
            $upsert,
            $this->names->watchInstallFunction(),
        ), sprintf($rows === [] ? 'No shared trigger for "%s" (%s sync, %s level)' : 'Shared trigger and change log watches of "%s"', $index->name, $index->sync->value, $index->triggerLevel->value));
    }

    /** Forgets the watches of an index and removes the shared trigger of every table nobody watches any more. */
    public function unregister(IndexDefinition $index): Statement
    {
        return new Statement(sprintf(
            <<<'SQL'
                DO %1$s
                DECLARE
                    r record;
                    v_tables jsonb;
                BEGIN
                    IF to_regclass(%2$s) IS NOT NULL THEN
                        WITH gone AS (DELETE FROM %3$s WHERE index_name = %4$s RETURNING tbl, fn)
                        SELECT coalesce(jsonb_agg(DISTINCT jsonb_build_array(tbl, fn)), '[]'::jsonb) INTO v_tables FROM gone;
                        IF to_regprocedure(%5$s) IS NOT NULL THEN
                            FOR r IN SELECT e->>0 AS tbl, e->>1 AS fn FROM jsonb_array_elements(v_tables) AS e LOOP
                                PERFORM %6$s(r.tbl, r.fn);
                            END LOOP;
                        END IF;
                    END IF;
                END
                %1$s
                SQL,
            self::TAG,
            Sql::string($this->names->watches()),
            $this->names->watches(),
            Sql::string($index->name),
            Sql::string($this->names->watchInstallFunction() . '(text, text)'),
            $this->names->watchInstallFunction(),
        ), sprintf('Remove the watches of "%s" and the shared triggers nobody needs any more', $index->name));
    }

    /**
     * Moves what the shared log has recorded for the index since its last drain into its queue: for every watch the document ids
     * its query returns for the changed keys (only the changes that touch a column it lists, when it lists some) and the effect of
     * a TRUNCATE. Returns the number of queue rows added. Several workers may call it at once: a watch another one is draining is
     * skipped.
     *
     * @param callable(IndexDefinition, Watch): ?list<string> $columns
     */
    public function drainFunction(IndexDefinition $index, callable $columns, string $sidecar, string $changesTable): string
    {
        $name = Sql::string($index->name);
        $log = $this->names->watchLog();
        $reg = $this->names->watches();
        $queue = $this->names->queue();
        $body = [];
        $tables = [];
        foreach ($index->effectiveWatches() as $no => $watch) {
            $tbl = Sql::ident($watch->table);
            $cols = $columns($index, $watch);
            $tables[$tbl] = true;
            $relevant = $cols === null ? 'true' : sprintf("(r.op <> 'U' OR jsonb_exists_any(r.chg, ARRAY[%s]::text[]))", implode(', ', array_map(Sql::string(...), ['*', ...$cols])));
            $affected = (string) preg_replace('/:id\b/', 'k.' . Sql::ident($watch->keyColumn), $watch->affectedIds, 1);
            $range = sprintf('tbl = %s AND op = \'T\' AND xid >= v_lo AND xid < v_hi', Sql::string($tbl));
            $resync = sprintf("INSERT INTO %s (index_name, doc_id, queued_at) VALUES (%s, '*', clock_timestamp())\n                    ON CONFLICT (index_name, doc_id) DO UPDATE SET queued_at = clock_timestamp();", $queue, $name);
            if ($index->source->table !== null && $watch->table === $index->source->table) {
                $truncate = sprintf(
                    <<<'SQL'
                            IF NOT EXISTS (SELECT 1 FROM %1$s) THEN
                                DELETE FROM %2$s;
                                IF to_regclass(%3$s) IS NULL THEN
                                    DELETE FROM %4$s WHERE ctid IN (SELECT ctid FROM %4$s WHERE index_name = %5$s FOR UPDATE SKIP LOCKED);
                                END IF;
                            ELSE
                                %6$s
                            END IF;
                        SQL,
                    $tbl,
                    $sidecar,
                    Sql::string($changesTable),
                    $queue,
                    $name,
                    $resync,
                );
            } else {
                $truncate = '        ' . $resync;
            }
            $body[] = sprintf(
                <<<'SQL'
                        SELECT cur_xid INTO v_lo FROM %1$s WHERE index_name = %2$s AND watch_no = %3$d FOR UPDATE SKIP LOCKED;
                        IF FOUND AND v_lo < v_hi THEN
                            INSERT INTO %7$s (index_name, doc_id)
                            SELECT DISTINCT %2$s, a.doc_id::text
                              FROM %4$s AS r
                              CROSS JOIN LATERAL jsonb_populate_record(NULL::%8$s, jsonb_build_object(%9$s, r.key)) AS k
                              CROSS JOIN LATERAL (%10$s) AS a(doc_id)
                             WHERE r.tbl = %11$s AND r.key_column = %9$s AND r.op <> 'T' AND r.xid >= v_lo AND r.xid < v_hi AND %12$s AND a.doc_id IS NOT NULL
                            ON CONFLICT (index_name, doc_id) DO NOTHING;
                            GET DIAGNOSTICS v_m = ROW_COUNT;
                            v_n := v_n + v_m;
                            IF EXISTS (SELECT 1 FROM %4$s WHERE %5$s) THEN
                    %6$s
                            END IF;
                            UPDATE %1$s SET cur_xid = v_hi WHERE index_name = %2$s AND watch_no = %3$d;
                            v_done := true;
                        END IF;
                    SQL,
                $reg,
                $name,
                $no,
                $log,
                $range,
                $truncate,
                $queue,
                $tbl,
                Sql::string($watch->keyColumn),
                $affected,
                Sql::string($tbl),
                $relevant,
            );
        }
        $prune = [];
        foreach (array_keys($tables) as $tbl) {
            // what every index has read: the shared log keeps nothing else (an entry another worker holds is left for the next drain)
            $prune[] = sprintf(
                'DELETE FROM %1$s WHERE ctid IN (SELECT l.ctid FROM %1$s AS l WHERE l.tbl = %2$s AND l.xid < (SELECT min(w.cur_xid) FROM %3$s AS w WHERE w.tbl = %2$s) FOR UPDATE SKIP LOCKED);',
                $log,
                Sql::string($tbl),
                $reg,
            );
        }

        return sprintf(
            "CREATE OR REPLACE FUNCTION %1\$s() RETURNS integer\nLANGUAGE plpgsql SET search_path FROM CURRENT AS %2\$s\nDECLARE\n    v_hi bigint := pg_snapshot_xmin(pg_current_snapshot())::text::bigint;\n    v_lo bigint;\n    v_n integer := 0;\n    v_m integer;\n    v_done boolean := false;\nBEGIN\n%3\$s\n    IF v_done THEN\n        %4\$s\n    END IF;\n    RETURN v_n;\nEND\n%2\$s",
            $this->names->drainFunction($index),
            self::TAG,
            implode("\n", $body),
            implode("\n        ", $prune),
        );
    }

    private function installFunction(): string
    {
        $log = $this->names->watchLog();
        $reg = $this->names->watches();
        $sql = <<<'SQL'
            CREATE OR REPLACE FUNCTION @FN@(p_tbl text, p_fn text) RETURNS void
            LANGUAGE plpgsql AS $fzi$
            DECLARE
                v_fn text := format('%I.%I', @SCHEMA@::text, p_fn);
                v_n integer;
                v_keys text[];
                v_cols text[];
                v_any boolean;
                v_k text;
                v_ins text := '';
                v_del text := '';
                v_upd text := '';
                v_diff text;
                r record;
            BEGIN
                SELECT count(*), coalesce(array_agg(DISTINCT w.key_column), '{}'), coalesce(bool_or(w.columns IS NULL), false)
                  INTO v_n, v_keys, v_any FROM @REG@ AS w WHERE w.tbl = p_tbl;
                IF v_n = 0 THEN
                    FOR r IN SELECT n.nspname, c.relname, g.tgname FROM pg_trigger AS g JOIN pg_class AS c ON c.oid = g.tgrelid JOIN pg_namespace AS n ON n.oid = c.relnamespace WHERE g.tgfoid = to_regprocedure(v_fn || '()') LOOP
                        EXECUTE format('DROP TRIGGER %I ON %I.%I', r.tgname, r.nspname, r.relname);
                    END LOOP;
                    EXECUTE 'DROP FUNCTION IF EXISTS ' || v_fn || '()';
                    DELETE FROM @LOG@ WHERE tbl = p_tbl;
                    RETURN;
                END IF;
                SELECT coalesce(array_agg(DISTINCT x), '{}') INTO v_cols FROM @REG@ AS w, jsonb_array_elements_text(w.columns) AS x WHERE w.tbl = p_tbl AND w.columns IS NOT NULL;
                FOREACH v_k IN ARRAY v_keys LOOP
                    v_ins := v_ins || format('INSERT INTO @LOG@ (tbl, xid, op, key_column, key) SELECT %L, v_x, ''I'', %L, r.%I::text FROM fz_new AS r; ', p_tbl, v_k, v_k);
                    v_del := v_del || format('INSERT INTO @LOG@ (tbl, xid, op, key_column, key) SELECT %L, v_x, ''D'', %L, r.%I::text FROM fz_old AS r; ', p_tbl, v_k, v_k);
                    IF cardinality(v_cols) = 0 THEN
                        v_upd := v_upd || format('INSERT INTO @LOG@ (tbl, xid, op, key_column, key) SELECT %1$L, v_x, ''U'', %2$L, r.%2$I::text FROM fz_new AS r; INSERT INTO @LOG@ (tbl, xid, op, key_column, key) SELECT %1$L, v_x, ''U'', %2$L, r.%2$I::text FROM fz_old AS r; ', p_tbl, v_k);
                    ELSE
                        SELECT string_agg(format('CASE WHEN r.%1$I IS DISTINCT FROM o.%1$I THEN %1$L END', x), ', ') INTO v_diff FROM unnest(v_cols) AS x;
                        v_upd := v_upd || format(
                            'INSERT INTO @LOG@ (tbl, xid, op, key_column, key, chg) SELECT %1$L, v_x, ''U'', %2$L, r.%2$I::text, x.chg FROM fz_new AS r LEFT JOIN fz_old AS o ON o.%2$I = r.%2$I CROSS JOIN LATERAL (SELECT CASE WHEN o.%2$I IS NULL THEN ''["*"]''::jsonb ELSE to_jsonb(array_remove(ARRAY[%3$s]::text[], NULL)) END AS chg) AS x WHERE %4$s OR jsonb_array_length(x.chg) > 0; '
                            || 'INSERT INTO @LOG@ (tbl, xid, op, key_column, key, chg) SELECT %1$L, v_x, ''U'', %2$L, r.%2$I::text, ''["*"]''::jsonb FROM fz_old AS r WHERE NOT EXISTS (SELECT 1 FROM fz_new AS n WHERE n.%2$I = r.%2$I); ',
                            p_tbl, v_k, v_diff, v_any::text);
                    END IF;
                END LOOP;
                EXECUTE format($b$CREATE OR REPLACE FUNCTION %1$s() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $fzb$
            DECLARE
                v_x bigint := pg_current_xact_id()::text::bigint;
            BEGIN
                IF TG_OP = 'TRUNCATE' THEN
                    INSERT INTO @LOG@ (tbl, xid, op, key_column) VALUES (%2$L, v_x, 'T', '');
                    RETURN NULL;
                END IF;
                IF TG_OP = 'INSERT' THEN %3$s END IF;
                IF TG_OP = 'DELETE' THEN %4$s END IF;
                IF TG_OP = 'UPDATE' THEN %5$s END IF;
                RETURN NULL;
            END
            $fzb$$b$, v_fn, p_tbl, v_ins, v_del, v_upd);
                EXECUTE format('CREATE OR REPLACE TRIGGER %I AFTER INSERT ON %s REFERENCING NEW TABLE AS fz_new FOR EACH STATEMENT EXECUTE FUNCTION %s()', p_fn || '__ins', p_tbl, v_fn);
                EXECUTE format('CREATE OR REPLACE TRIGGER %I AFTER UPDATE ON %s REFERENCING OLD TABLE AS fz_old NEW TABLE AS fz_new FOR EACH STATEMENT EXECUTE FUNCTION %s()', p_fn || '__upd', p_tbl, v_fn);
                EXECUTE format('CREATE OR REPLACE TRIGGER %I AFTER DELETE ON %s REFERENCING OLD TABLE AS fz_old FOR EACH STATEMENT EXECUTE FUNCTION %s()', p_fn || '__del', p_tbl, v_fn);
                EXECUTE format('CREATE OR REPLACE TRIGGER %I AFTER TRUNCATE ON %s FOR EACH STATEMENT EXECUTE FUNCTION %s()', p_fn || '__trn', p_tbl, v_fn);
                -- truncating one partition fires only that partition's triggers
                FOR r IN SELECT n.nspname, c.relname FROM pg_partition_tree(to_regclass(p_tbl)) AS t JOIN pg_class AS c ON c.oid = t.relid JOIN pg_namespace AS n ON n.oid = c.relnamespace WHERE t.level > 0 LOOP
                    EXECUTE format('CREATE OR REPLACE TRIGGER %I AFTER TRUNCATE ON %I.%I FOR EACH STATEMENT EXECUTE FUNCTION %s()', p_fn || '__trn', r.nspname, r.relname, v_fn);
                END LOOP;
                -- a detached partition keeps its TRUNCATE trigger
                FOR r IN SELECT n.nspname, c.relname, g.tgname FROM pg_trigger AS g JOIN pg_class AS c ON c.oid = g.tgrelid JOIN pg_namespace AS n ON n.oid = c.relnamespace
                          WHERE g.tgfoid = to_regprocedure(v_fn || '()') AND g.tgrelid IS DISTINCT FROM to_regclass(p_tbl) AND g.tgrelid NOT IN (SELECT t.relid FROM pg_partition_tree(to_regclass(p_tbl)) AS t) LOOP
                    EXECUTE format('DROP TRIGGER %I ON %I.%I', r.tgname, r.nspname, r.relname);
                END LOOP;
            END
            $fzi$
            SQL;

        return strtr($sql, [
            '@FN@' => $this->names->watchInstallFunction(),
            '@SCHEMA@' => Sql::string($this->names->schema),
            '@LOG@' => $log,
            '@REG@' => $reg,
        ]);
    }
}
