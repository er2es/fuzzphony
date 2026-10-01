<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

use Composer\InstalledVersions;
use Fuzzphony\Core\Definition\DefinitionValidator;
use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\TextConfig;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Engine\Postgres\Sql\DocumentSql;
use Fuzzphony\Engine\Postgres\Sql\FilterCompiler;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal Generates idempotent DDL. Nothing here ever alters the source tables, except for
 * (optional) AFTER triggers that feed the sync queue.
 */
final class PostgresSchemaGenerator
{
    /**
     * The sidecar layout this version generates, recorded in fuzzphony_meta: 1 = the 0.4 layout,
     * 2 = per-field columns (0.5). A layout change bumps it and adds its step to layoutSteps(),
     * with a test.
     */
    public const int LAYOUT_VERSION = 2;

    /** Every generated function body / DO block is quoted with this tag; the validator keeps it out of embedded SQL. */
    private const string TAG = DefinitionValidator::DOLLAR_QUOTE_TAG;

    public function __construct(private readonly Names $names = new Names()) {}

    public function names(): Names
    {
        return $this->names;
    }

    public function global(IndexDefinition ...$indexes): SchemaPlan
    {
        $schema = $this->names->extensionSchema;
        $statements = [];
        if ($this->names->schema !== 'public') {
            // not for public: PostgreSQL checks CREATE on the database before IF NOT EXISTS
            $statements[] = new Statement(sprintf('CREATE SCHEMA IF NOT EXISTS %s', $this->names->quotedSchema()), "Fuzzphony's own schema");
        }
        array_push(
            $statements,
            new Statement(sprintf('CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA %s', Sql::ident($schema)), 'Trigram matching for typo tolerance'),
            new Statement(sprintf('CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA %s', Sql::ident($schema)), 'Accent folding'),
            new Statement(sprintf(
                "CREATE OR REPLACE FUNCTION %s(text) RETURNS text\nLANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT\nSET search_path = pg_catalog, pg_temp\nAS " . self::TAG . " SELECT btrim(regexp_replace(lower(%s.unaccent(%s::regdictionary, \$1)), '[^[:alnum:]]+', ' ', 'g')) " . self::TAG,
                $this->names->normFunction(),
                Sql::ident($schema),
                Sql::string(Sql::ident($schema) . '.unaccent'),
            ), 'Normaliser for trigram / exact matching: lowercase, no accents, alphanumerics only'),
            new Statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s (\n    index_name text NOT NULL,\n    doc_id text NOT NULL,\n    queued_at timestamptz NOT NULL DEFAULT clock_timestamp(),\n    PRIMARY KEY (index_name, doc_id)\n)",
                $this->names->queue(),
            ), 'Sync queue shared by all indexes'),
            new Statement(sprintf('CREATE INDEX IF NOT EXISTS %s ON %s (index_name, queued_at)', $this->names->queueOrderIndex(), $this->names->queue()), 'Queue processing order'),
            new Statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s (
    index_name text PRIMARY KEY,
    layout_version integer NOT NULL,
    definition_hash text NOT NULL,
    documents_hash text,
    library_version text NOT NULL,
    applied_at timestamptz NOT NULL,
    reindexed_at timestamptz
)",
                $this->names->meta(),
            ), 'Layout and definition each index was built from'),
        );
        foreach (['rebuild_failed_at' => 'timestamptz', 'rebuild_failures' => 'integer', 'rebuild_error' => 'text'] as $column => $type) {
            $statements[] = new Statement(
                sprintf('ALTER TABLE %s ADD COLUMN IF NOT EXISTS %s %s', $this->names->meta(), $column, $type),
                'The last failure of the full rebuild a TRUNCATE queued (the doctor reports it)',
            );
        }

        $configs = [];
        foreach ($indexes as $index) {
            if ($index->text->unaccent) {
                $configs[$this->names->textConfigName($index->text)] = $index->text;
            }
        }
        foreach ($configs as $config) {
            $statements[] = $this->textConfig($config);
        }
        $statements[] = $this->recordApply('*', Fingerprint::shared($this->names), 'Record the layout of the shared objects');

        return new SchemaPlan($statements);
    }

    public function index(IndexDefinition $index): SchemaPlan
    {
        $table = $this->names->sidecar($index);
        $columns = $this->columns($index);

        $definitions = array_map(static fn(string $name, string $type): string => sprintf('    %s %s', Sql::ident($name), $type), array_keys($columns), $columns);
        $statements = [
            $this->rebuildGuard($index),
            new Statement(sprintf("CREATE TABLE IF NOT EXISTS %s (\n%s\n)", $table, implode(",\n", $definitions)), sprintf('Sidecar index table of "%s"', $index->name)),
        ];
        foreach (array_slice($columns, 1, null, true) as $name => $type) {
            $statements[] = new Statement(
                sprintf('ALTER TABLE %s ADD COLUMN IF NOT EXISTS %s %s', $table, Sql::ident($name), $type),
                sprintf('Keep column "%s" in sync with the definition', $name),
            );
        }
        $statements[] = new Statement($this->refreshFunction($index), 'Builds / removes documents by id');
        $statements[] = new Statement($this->refreshFunction($index, shadow: true), 'Builds / removes documents by id in the table a full reindex builds next to the live one');
        $statements[] = new Statement($this->trackFunction($index), 'Logs which documents change while a full reindex runs');

        foreach ($index->effectiveWatches() as $watch) {
            $watched = Sql::ident($watch->table);
            foreach ($this->obsoleteTriggerNames($index, $watch) as $obsolete) {
                $statements[] = new Statement(sprintf('DROP TRIGGER IF EXISTS %s ON %s', Sql::ident($obsolete), $watched), sprintf('Remove trigger not used in "%s" sync / %s level', $index->sync->value, $index->triggerLevel->value));
            }
            if (!$index->sync->usesTriggers()) {
                $statements[] = $this->partitionTriggers($index, $watch, false);

                continue;
            }
            $statements[] = new Statement(
                $index->triggerLevel === TriggerLevel::Statement ? $this->statementSyncFunction($index, $watch) : $this->syncFunction($index, $watch),
                sprintf('Sync trigger function for %s', $watch->table),
            );
            foreach ($this->triggerDefinitions($index, $watch) as $name => $definition) {
                $statements[] = new Statement(
                    sprintf('CREATE OR REPLACE TRIGGER %s %s ON %s %s EXECUTE FUNCTION %s()', Sql::ident($name), $definition['timing'], $watched, $definition['for'], $this->names->syncFunction($index, $watch)),
                    sprintf('%s sync on %s (%s level)', ucfirst($index->sync->value), $watch->table, $index->triggerLevel->value),
                );
            }
            $statements[] = $this->partitionTriggers($index, $watch, true);
        }

        foreach ($this->indexes($index) as $name => $definition) {
            $statements[] = new Statement(
                sprintf('CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s %s', Sql::ident($name), $table, $definition),
                sprintf('Index %s', $name),
                transactional: false,
            );
        }
        foreach ($this->layoutSteps($index) as $target => [$step, $why]) {
            $statements[] = new Statement(sprintf(
                'DO %1$s BEGIN IF to_regclass(%2$s) IS NOT NULL THEN IF (SELECT layout_version FROM %3$s WHERE index_name = %4$s) < %5$d THEN %6$s END IF; END IF; END %1$s',
                self::TAG,
                Sql::string($this->names->meta()),
                $this->names->meta(),
                Sql::string($index->name),
                $target,
                $step,
            ), sprintf('Layout step to %d: %s', $target, $why));
        }
        $statements[] = $this->recordApply($index->name, Fingerprint::definition($index), sprintf('Record the layout and definition "%s" was built from', $index->name));

        return new SchemaPlan($statements);
    }

    /**
     * First in the apply transaction: fails it while a full reindex of the index runs (it holds
     * the rebuild lock, Names::rebuildLockKey()), because the plan replaces the functions that
     * rebuild uses (a new layout would break it), and holds the lock until the transaction ends,
     * so no rebuild starts meanwhile. Also first in drop(). A DO block, so --dump-migration keeps
     * the guard, but a migration is not transactional: there it only refuses while a rebuild is
     * running at that statement.
     */
    private function rebuildGuard(IndexDefinition $index): Statement
    {
        return new Statement(sprintf(
            'DO %1$s BEGIN IF NOT pg_try_advisory_xact_lock(hashtext(%2$s)) THEN RAISE EXCEPTION USING MESSAGE = %3$s; END IF; END %1$s',
            self::TAG,
            Sql::string($this->names->rebuildLockKey($index)),
            Sql::string(sprintf('A rebuild of "%s" is running (fuzzphony:reindex): apply the schema again when it has finished, a new layout would break it.', $index->name)),
        ), sprintf('Refuse while a full reindex of "%s" runs', $index->name));
    }

    public function drop(IndexDefinition $index): SchemaPlan
    {
        $statements = [$this->rebuildGuard($index)];
        foreach ($index->effectiveWatches() as $watch) {
            foreach ($this->allTriggerNames($index, $watch) as $trigger) {
                $statements[] = new Statement(sprintf('DROP TRIGGER IF EXISTS %s ON %s', Sql::ident($trigger), Sql::ident($watch->table)), 'Remove sync trigger');
            }
            $statements[] = $this->partitionTriggers($index, $watch, false);
            $statements[] = new Statement(sprintf('DROP FUNCTION IF EXISTS %s()', $this->names->syncFunction($index, $watch)), 'Remove sync function');
        }
        $statements[] = new Statement(sprintf('DROP FUNCTION IF EXISTS %s(%s[])', $this->names->refreshFunction($index), Types::id($index->idType)), 'Remove refresh function');
        $statements[] = new Statement(sprintf('DROP TABLE IF EXISTS %s', $this->names->sidecar($index)), 'Remove sidecar table');
        $statements[] = new Statement(sprintf('DROP TABLE IF EXISTS %s', $this->names->shadow($index)), 'Remove a rebuild that did not finish');
        $statements[] = new Statement(sprintf('DROP TABLE IF EXISTS %s', $this->names->changes($index)), 'Remove its change log');
        $statements[] = new Statement(sprintf('DROP FUNCTION IF EXISTS %s(%s[])', $this->names->shadowRefreshFunction($index), Types::id($index->idType)), 'Remove the rebuild refresh function');
        $statements[] = new Statement(sprintf('DROP FUNCTION IF EXISTS %s()', $this->names->trackFunction($index)), 'Remove the change log function');
        $statements[] = new Statement(sprintf(
            "DO " . self::TAG . " BEGIN IF to_regclass(%s) IS NOT NULL THEN DELETE FROM %s WHERE index_name = %s; END IF; END " . self::TAG,
            Sql::string($this->names->queue()),
            $this->names->queue(),
            Sql::string($index->name),
        ), 'Forget queued items');
        $statements[] = new Statement(sprintf(
            'DO ' . self::TAG . ' BEGIN IF to_regclass(%s) IS NOT NULL THEN DELETE FROM %s WHERE index_name = %s; END IF; END ' . self::TAG,
            Sql::string($this->names->meta()),
            $this->names->meta(),
            Sql::string($index->name),
        ), 'Forget the version record');

        return new SchemaPlan($statements);
    }

    /** Records that a full reindex rebuilt every document from this definition; a no-op before the meta table exists. */
    public function reindexed(IndexDefinition $index): string
    {
        return sprintf(
            'DO ' . self::TAG . ' BEGIN IF to_regclass(%s) IS NOT NULL THEN UPDATE %s SET documents_hash = %s, reindexed_at = now() WHERE index_name = %s; END IF; END ' . self::TAG,
            Sql::string($this->names->meta()),
            $this->names->meta(),
            Sql::string(Fingerprint::documents($index)),
            Sql::string($index->name),
        );
    }

    /**
     * The meta row upsert. Not transactional, so SchemaPlan::apply() runs it after everything else,
     * including the concurrent index builds: the row only claims what was actually built.
     */
    private function recordApply(string $indexName, string $definitionHash, string $description): Statement
    {
        return new Statement(sprintf(
            "INSERT INTO %s (index_name, layout_version, definition_hash, library_version, applied_at)
VALUES (%s, %d, %s, %s, now())
ON CONFLICT (index_name) DO UPDATE SET layout_version = EXCLUDED.layout_version, definition_hash = EXCLUDED.definition_hash, library_version = EXCLUDED.library_version, applied_at = EXCLUDED.applied_at",
            $this->names->meta(),
            Sql::string($indexName),
            self::LAYOUT_VERSION,
            Sql::string($definitionHash),
            Sql::string(self::libraryVersion()),
        ), $description, transactional: false);
    }

    /**
     * The sidecar layout upgrade steps, by the layout each one leads to. schema --apply runs a
     * step for an index whose stored layout (fuzzphony_meta) is older, in the apply transaction,
     * before the meta upsert records the new layout; a missing version table or row runs none.
     * The check is SQL (a DO block), so the plan stays database-free and --dump-migration contains
     * the steps. New columns come from the plan's ADD COLUMN IF NOT EXISTS, not from a step. The
     * SELECT sits in its own IF: plpgsql plans an IF expression as a whole, so
     * "to_regclass(...) IS NOT NULL AND (SELECT ...)" would fail on a missing table.
     *
     * @return array<int, array{string, string}> target layout => [the step's SQL, why it exists]
     */
    private function layoutSteps(IndexDefinition $index): array
    {
        return [
            2 => [
                sprintf('UPDATE %s SET documents_hash = NULL WHERE index_name = %s;', $this->names->meta(), Sql::string($index->name)),
                'the per-field columns of existing documents stay empty until a full reindex',
            ],
        ];
    }

    /** For the record only: the monorepo package, or the engine package when installed split (as in the demo). */
    private static function libraryVersion(): string
    {
        return InstalledVersions::getPrettyVersion(InstalledVersions::isInstalled('fuzzphony/fuzzphony') ? 'fuzzphony/fuzzphony' : 'fuzzphony/postgres-engine') ?? 'unknown';
    }

    /**
     * Expected sidecar columns (name => type); the first one is the primary key.
     *
     * @return array<string, string>
     */
    public function columns(IndexDefinition $index): array
    {
        $columns = [
            'id' => Types::id($index->idType) . ' PRIMARY KEY',
            'tsv' => 'tsvector NOT NULL',
            'fz' => "text NOT NULL DEFAULT ''",
            'exact' => "text NOT NULL DEFAULT ''",
        ];
        foreach ($index->fields as $field) {
            $columns[$this->names->fieldVectorName($field->name)] = "tsvector NOT NULL DEFAULT ''";
            if ($field->fuzzy) {
                $columns[$this->names->fieldFuzzyName($field->name)] = "text NOT NULL DEFAULT ''";
            }
        }
        if ($index->boostColumn !== null) {
            $columns['boost'] = 'double precision';
        }
        if ($index->recencyColumn !== null) {
            $columns['recency_at'] = 'timestamptz';
        }
        foreach ($index->filters as $filter) {
            $columns['f_' . $filter->name] = Types::filter($filter->type);
        }
        $columns['indexed_at'] = 'timestamptz NOT NULL DEFAULT now()';

        return $columns;
    }

    /**
     * Which columns of $watch->table an UPDATE must change to warrant a refresh — null means
     * every UPDATE refreshes (today's behavior, unchanged). Explicit Watch::$columns always wins;
     * otherwise, for the automatic self-watch on a table source, the relevant columns are derived
     * from the index's own fields, filters, boost and recency columns.
     *
     * @return list<string>|null
     */
    public function relevantColumns(IndexDefinition $index, Watch $watch): ?array
    {
        if ($watch->columns !== null) {
            return $watch->columns !== [] ? $watch->columns : null;
        }
        if ($index->source->table === null || $watch->table !== $index->source->table) {
            return null;
        }

        $columns = [];
        foreach ($index->fields as $field) {
            $columns[] = $field->column();
        }
        foreach ($index->filters as $filter) {
            $columns[] = $filter->column();
        }
        if ($index->boostColumn !== null) {
            $columns[] = $index->boostColumn;
        }
        if ($index->recencyColumn !== null) {
            $columns[] = $index->recencyColumn;
        }

        return array_values(array_unique($columns));
    }

    /**
     * Expected secondary indexes (name => "USING ... (...)").
     *
     * @return array<string, string>
     */
    public function indexes(IndexDefinition $index): array
    {
        $indexes = [$this->names->indexName($index, 'tsv') => 'USING gin (tsv)'];
        if ($index->hasFuzzy()) {
            $indexes[$this->names->indexName($index, 'fz')] = sprintf('USING gin (fz %s.gin_trgm_ops)', $this->names->extension());
        }
        foreach ($index->filters as $filter) {
            $indexes[$this->names->indexName($index, 'f_' . $filter->name)] = sprintf('(%s)', FilterCompiler::column($filter->name));
        }

        return $indexes;
    }

    /**
     * Triggers the definition needs (name => timing / FOR clause); empty when the sync mode uses none.
     *
     * @return array<string, array{timing: string, for: string}>
     */
    public function triggerDefinitions(IndexDefinition $index, Watch $watch): array
    {
        if (!$index->sync->usesTriggers()) {
            return [];
        }
        // PostgreSQL never runs DELETE triggers for TRUNCATE and only allows TRUNCATE triggers per
        // statement (without transition tables), so both levels get the same extra trigger.
        $truncate = [$this->names->triggerName($index, $watch, '_trn') => ['timing' => 'AFTER TRUNCATE', 'for' => 'FOR EACH STATEMENT']];
        if ($index->triggerLevel === TriggerLevel::Row) {
            return [$this->names->triggerName($index, $watch) => ['timing' => 'AFTER INSERT OR UPDATE OR DELETE', 'for' => 'FOR EACH ROW']] + $truncate;
        }

        // Transition tables require one trigger per event.
        return [
            $this->names->triggerName($index, $watch, '_ins') => ['timing' => 'AFTER INSERT', 'for' => 'REFERENCING NEW TABLE AS fz_new FOR EACH STATEMENT'],
            $this->names->triggerName($index, $watch, '_upd') => ['timing' => 'AFTER UPDATE', 'for' => 'REFERENCING OLD TABLE AS fz_old NEW TABLE AS fz_new FOR EACH STATEMENT'],
            $this->names->triggerName($index, $watch, '_del') => ['timing' => 'AFTER DELETE', 'for' => 'REFERENCING OLD TABLE AS fz_old FOR EACH STATEMENT'],
        ] + $truncate;
    }

    /** @return list<string> every trigger name this watch can ever have */
    public function allTriggerNames(IndexDefinition $index, Watch $watch): array
    {
        return [
            $this->names->triggerName($index, $watch),
            $this->names->triggerName($index, $watch, '_ins'),
            $this->names->triggerName($index, $watch, '_upd'),
            $this->names->triggerName($index, $watch, '_del'),
            $this->names->triggerName($index, $watch, '_trn'),
        ];
    }

    /** @return list<string> trigger names that must NOT exist for the current sync mode / level */
    public function obsoleteTriggerNames(IndexDefinition $index, Watch $watch): array
    {
        return array_values(array_diff($this->allTriggerNames($index, $watch), array_keys($this->triggerDefinitions($index, $watch))));
    }

    /**
     * The TRUNCATE trigger on every partition of a partitioned watched table, at every level:
     * truncating one partition fires only that partition's triggers. pg_partition_tree() lists the
     * partitions when the plan runs (so the plan stays database-free) and nothing for a table that
     * is not partitioned. Each table's schema and name go through %I, so any name is
     * quoted right. The trigger name is the parent's: trigger names are per table. Row-level
     * triggers are cloned to partitions by PostgreSQL (and removed on DETACH); statement-level
     * ones cannot go on them.
     *
     * A detached partition keeps its TRUNCATE trigger, so the triggers to remove are found by
     * name and sync function on any table: with $create those on a table that is no longer in the
     * partition tree, without it (a sync mode without triggers, drop()) all but the parent's, which
     * the caller drops by name. drop() then drops the sync function, which they depend on.
     */
    private function partitionTriggers(IndexDefinition $index, Watch $watch, bool $create): Statement
    {
        $trigger = Sql::string($this->names->triggerName($index, $watch, '_trn'));
        $function = $this->names->syncFunction($index, $watch);
        $table = Sql::string(Sql::ident($watch->table));
        $loops = [sprintf(
            <<<'SQL'
                FOR r IN SELECT n.nspname, c.relname FROM pg_trigger AS g JOIN pg_class AS c ON c.oid = g.tgrelid JOIN pg_namespace AS n ON n.oid = c.relnamespace WHERE g.tgname = %1$s AND g.tgfoid = to_regprocedure(%2$s) AND %3$s LOOP
                        EXECUTE format('DROP TRIGGER %%I ON %%I.%%I', %1$s, r.nspname, r.relname);
                    END LOOP;
                SQL,
            $trigger,
            Sql::string($function . '()'),
            // pg_partition_tree() is empty for a table that is not partitioned: the parent is excluded on its own
            sprintf('g.tgrelid IS DISTINCT FROM to_regclass(%s)', $table) . ($create ? sprintf(' AND g.tgrelid NOT IN (SELECT t.relid FROM pg_partition_tree(to_regclass(%s)) AS t)', $table) : ''),
        )];
        if ($create) {
            $loops[] = sprintf(
                <<<'SQL'
                    FOR r IN SELECT n.nspname, c.relname FROM pg_partition_tree(to_regclass(%1$s)) AS t JOIN pg_class AS c ON c.oid = t.relid JOIN pg_namespace AS n ON n.oid = c.relnamespace WHERE t.level > 0 LOOP
                            EXECUTE format('CREATE OR REPLACE TRIGGER %%I AFTER TRUNCATE ON %%I.%%I FOR EACH STATEMENT EXECUTE FUNCTION %%s()', %2$s, r.nspname, r.relname, %3$s);
                        END LOOP;
                    SQL,
                $table,
                $trigger,
                Sql::string($function),
            );
        }

        return new Statement(sprintf(
            <<<'SQL'
                DO %1$s
                DECLARE
                    r record;
                BEGIN
                    %2$s
                END
                %1$s
                SQL,
            self::TAG,
            implode("
    ", $loops),
        ), sprintf($create ? 'TRUNCATE sync on every partition of %s' : 'No TRUNCATE sync on the partitions of %s', $watch->table));
    }

    /**
     * `SET search_path FROM CURRENT` stores the search_path setting of the session that applied the
     * schema, as written: the embedded source query and watch SQL resolve their (usually
     * unqualified) tables through it, whatever the caller's own search_path is. The setting is
     * stored, not the schemas it named then: with the default `"$user", public`, `$user` is
     * evaluated when the function runs, as the calling role (the functions are SECURITY INVOKER),
     * so a caller with a schema of its own name can still shadow a source table. Fuzzphony's own
     * objects are schema-qualified and never depend on it. With $shadow, the same function for the
     * table a full reindex builds (Names::shadow()): two static functions keep the SQL plan-cached,
     * no EXECUTE per batch.
     *
     * While a full reindex runs, the live function logs every id it is given (ADR 0008): a document
     * the live table never had (queued or not yet refreshed when the rebuild loaded it, deleted
     * since) writes nothing there, so the change log trigger alone would miss it. The log comes after
     * the writes: the function takes the live table's lock before the log's, like the swap, so the two
     * never deadlock. It locks the logged rows (see trackFunction()) in id order, one row per id
     * (DO UPDATE cannot touch a row twice in one statement).
     */
    private function refreshFunction(IndexDefinition $index, bool $shadow = false): string
    {
        $table = $shadow ? $this->names->shadow($index) : $this->names->sidecar($index);
        $columns = ['id', 'tsv', 'fz', 'exact'];
        $values = ['doc.fz_id', $this->tsvectorExpression($index), $this->fuzzyExpression($index), $this->exactExpression($index)];
        foreach ($index->fields as $field) {
            $columns[] = $this->names->fieldVectorName($field->name);
            $values[] = $this->fieldVectorExpression($index, $field);
            if ($field->fuzzy) {
                $columns[] = $this->names->fieldFuzzyName($field->name);
                $values[] = sprintf("coalesce(%s, '')", $this->fieldFuzzyExpression($field));
            }
        }
        if ($index->boostColumn !== null) {
            $columns[] = 'boost';
            $values[] = 'doc.fz_boost::double precision';
        }
        if ($index->recencyColumn !== null) {
            $columns[] = 'recency_at';
            $values[] = 'doc.fz_recency::timestamptz';
        }
        foreach ($index->filters as $filter) {
            $columns[] = 'f_' . $filter->name;
            $values[] = sprintf('doc.%s::%s', Sql::ident('flt_' . $filter->name), Types::filter($filter->type));
        }
        $columns[] = 'indexed_at';
        $values[] = 'now()';

        $updates = implode(",\n        ", array_map(
            static fn(string $c): string => sprintf('%1$s = EXCLUDED.%1$s', Sql::ident($c)),
            array_slice($columns, 1),
        ));
        $document = DocumentSql::select($index);

        return sprintf(
            <<<'SQL'
                CREATE OR REPLACE FUNCTION %1$s(p_ids %2$s[]) RETURNS integer
                LANGUAGE plpgsql SET search_path FROM CURRENT AS %8$s
                DECLARE
                    written integer;
                BEGIN
                    IF p_ids IS NULL OR cardinality(p_ids) = 0 THEN
                        RETURN 0;
                    END IF;

                    INSERT INTO %3$s AS s (%4$s)
                    SELECT DISTINCT ON (doc.fz_id)
                        %5$s
                    FROM (%6$s) AS doc
                    WHERE doc.fz_id = ANY(p_ids)
                    ORDER BY doc.fz_id
                    ON CONFLICT (id) DO UPDATE SET
                        %7$s;
                    GET DIAGNOSTICS written = ROW_COUNT;

                    DELETE FROM %3$s AS s
                    WHERE s.id = ANY(p_ids)
                      AND NOT EXISTS (SELECT 1 FROM (%6$s) AS doc WHERE doc.fz_id = s.id);%9$s

                    RETURN written;
                END
                %8$s
                SQL,
            $shadow ? $this->names->shadowRefreshFunction($index) : $this->names->refreshFunction($index),
            Types::id($index->idType),
            $table,
            implode(', ', array_map(Sql::ident(...), $columns)),
            implode(",\n        ", $values),
            $document,
            $updates,
            self::TAG,
            $shadow ? '' : sprintf(
                "

    IF to_regclass(%s) IS NOT NULL THEN
        INSERT INTO %s (id) SELECT DISTINCT u.id FROM unnest(p_ids) AS u(id) WHERE u.id IS NOT NULL ORDER BY u.id ON CONFLICT (id) DO UPDATE SET id = EXCLUDED.id;
    END IF;",
                Sql::string($this->names->changes($index)),
                $this->names->changes($index),
            ),
        );
    }

    /**
     * Logs the id of every live document that changes while a full reindex runs (a row trigger
     * on the live table, created by beginRebuild(), gone with the old table after the swap). It
     * runs as the writer, in the writer's transaction; beginRebuild() grants the log to them. A
     * conflict updates the logged row, which locks it until the writer commits (DO NOTHING would
     * not): a catch-up batch can neither take the id meanwhile nor refresh it from the old state.
     */
    private function trackFunction(IndexDefinition $index): string
    {
        return sprintf(
            <<<'SQL'
                CREATE OR REPLACE FUNCTION %1$s() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS %3$s
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        INSERT INTO %2$s (id) VALUES (OLD.id) ON CONFLICT (id) DO UPDATE SET id = EXCLUDED.id;
                    ELSE
                        INSERT INTO %2$s (id) VALUES (NEW.id) ON CONFLICT (id) DO UPDATE SET id = EXCLUDED.id;
                    END IF;
                    RETURN NULL;
                END
                %3$s
                SQL,
            $this->names->trackFunction($index),
            $this->names->changes($index),
            self::TAG,
        );
    }

    /**
     * Starts a full rebuild next to the live table, in one statement: drops a leftover one,
     * creates the change log (writable by every role that may write the live table) and the
     * empty rebuild table (the current layout, without secondary indexes: shadowIndexes() adds
     * them after the load), and starts logging the live table. It locks the live table first (the
     * lock CREATE TRIGGER needs anyway), which waits for the transactions writing it, so every later
     * change is logged; writers lock the live table before the log, so dropping a leftover log
     * second cannot deadlock with them.
     */
    public function beginRebuild(IndexDefinition $index): string
    {
        return sprintf(
            <<<'SQL'
                DO %1$s
                DECLARE
                    r record;
                BEGIN
                    LOCK TABLE %9$s IN SHARE ROW EXCLUSIVE MODE;
                    DROP TABLE IF EXISTS %2$s;
                    DROP TABLE IF EXISTS %3$s;
                    CREATE TABLE %3$s (id %4$s PRIMARY KEY);
                    FOR r IN SELECT DISTINCT a.grantee
                             FROM pg_class AS c, aclexplode(coalesce(c.relacl, acldefault('r', c.relowner))) AS a
                             WHERE c.oid = %5$s::regclass AND a.privilege_type IN ('INSERT', 'UPDATE', 'DELETE') LOOP
                        EXECUTE format('GRANT SELECT, INSERT, UPDATE ON %%s TO %%s', %6$s, CASE WHEN r.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(r.grantee)) END);
                    END LOOP;
                    %7$s;
                    CREATE OR REPLACE TRIGGER %8$s AFTER INSERT OR UPDATE OR DELETE ON %9$s FOR EACH ROW EXECUTE FUNCTION %10$s();
                END
                %1$s
                SQL,
            self::TAG,
            $this->names->shadow($index),
            $this->names->changes($index),
            Types::id($index->idType),
            Sql::string($this->names->sidecar($index)),
            Sql::string($this->names->changes($index)),
            $this->shadowTable($index),
            Sql::ident($this->names->trackFunctionName($index)),
            $this->names->sidecar($index),
            $this->names->trackFunction($index),
        );
    }

    /** The rebuild table: the current layout (columns()), its primary key named for the swap. */
    private function shadowTable(IndexDefinition $index): string
    {
        $columns = $this->columns($index);
        $columns['id'] = Types::id($index->idType) . ' NOT NULL';
        $definitions = array_map(static fn(string $name, string $type): string => sprintf('    %s %s', Sql::ident($name), $type), array_keys($columns), $columns);
        $definitions[] = sprintf('    CONSTRAINT %s PRIMARY KEY (%s)', Sql::ident($this->names->shadowIndexName($this->names->indexName($index, 'pkey'))), Sql::ident('id'));

        return sprintf("CREATE TABLE %s (\n%s\n)", $this->names->shadow($index), implode(",\n", $definitions));
    }

    /**
     * The rebuild table's secondary indexes, built once it is loaded (faster than maintaining
     * them during the load; the table is not live, so nothing waits), and fresh statistics.
     *
     * @return list<string>
     */
    public function shadowIndexes(IndexDefinition $index): array
    {
        $statements = [];
        foreach ($this->indexes($index) as $name => $definition) {
            $statements[] = sprintf('CREATE INDEX IF NOT EXISTS %s ON %s %s', Sql::ident($this->names->shadowIndexName($name)), $this->names->shadow($index), $definition);
        }
        $statements[] = sprintf('ANALYZE %s', $this->names->shadow($index));

        return $statements;
    }

    /**
     * Swaps the rebuild in (run under ACCESS EXCLUSIVE on both tables): it gets the live table's
     * grants and owner (it was created by the reindexing role), the live table goes (with its
     * change log trigger), the rebuild takes the live name, its primary key and indexes the live
     * names. plpgsql resolves tables by name: the drop invalidates the cached plans of the live
     * refresh function, which writes the new table from its next call.
     */
    public function swap(IndexDefinition $index): string
    {
        $sidecar = $this->names->sidecar($index);
        $renames = '';
        foreach (array_keys($this->indexes($index)) as $name) {
            $renames .= sprintf("\n    ALTER INDEX %s RENAME TO %s;", $this->names->index($this->names->shadowIndexName($name)), Sql::ident($name));
        }
        $key = $this->names->indexName($index, 'pkey');

        return sprintf(
            <<<'SQL'
                DO %1$s
                DECLARE
                    r record;
                    v_owner text;
                BEGIN
                    FOR r IN SELECT a.privilege_type, a.is_grantable, CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(a.grantee)) END AS grantee
                             FROM pg_class AS c, aclexplode(c.relacl) AS a
                             WHERE c.oid = %2$s::regclass AND a.grantee <> c.relowner LOOP
                        EXECUTE format('GRANT %%s ON %%s TO %%s%%s', r.privilege_type, %3$s, r.grantee, CASE WHEN r.is_grantable THEN ' WITH GRANT OPTION' ELSE '' END);
                    END LOOP;
                    SELECT quote_ident(pg_get_userbyid(relowner)) INTO v_owner FROM pg_class WHERE oid = %2$s::regclass;
                    IF v_owner <> quote_ident(current_user) THEN
                        EXECUTE format('ALTER TABLE %%s OWNER TO %%s', %3$s, v_owner);
                    END IF;
                    DROP TABLE %4$s;
                    ALTER TABLE %5$s RENAME TO %6$s;
                    ALTER TABLE %4$s RENAME CONSTRAINT %7$s TO %8$s;%9$s
                    DROP TABLE %10$s;
                END
                %1$s
                SQL,
            self::TAG,
            Sql::string($sidecar),
            Sql::string($this->names->shadow($index)),
            $sidecar,
            $this->names->shadow($index),
            Sql::ident($this->names->sidecarName($index)),
            Sql::ident($this->names->shadowIndexName($key)),
            Sql::ident($key),
            $renames,
            $this->names->changes($index),
        );
    }

    /** Discards a rebuild: the change log trigger, the rebuild table and the log. */
    public function discardRebuild(IndexDefinition $index): string
    {
        return sprintf(
            'DO %1$s BEGIN IF to_regclass(%2$s) IS NOT NULL THEN DROP TRIGGER IF EXISTS %3$s ON %4$s; END IF; DROP TABLE IF EXISTS %5$s; DROP TABLE IF EXISTS %6$s; END %1$s',
            self::TAG,
            Sql::string($this->names->sidecar($index)),
            Sql::ident($this->names->trackFunctionName($index)),
            $this->names->sidecar($index),
            $this->names->shadow($index),
            $this->names->changes($index),
        );
    }

    /**
     * Completes a pending full-rebuild request (the "*" queue row, see truncateBranch()) after a
     * full run that started at $started (the database clock) succeeded: only a job queued before
     * that, since the run read the source after its TRUNCATE; a newer one stays for the next run.
     * Clears the index's recorded rebuild failure too. Each half is skipped without its table (or
     * the failure columns of an older schema) or without the rights to change it.
     */
    public function completeRebuildRequest(IndexDefinition $index, string $started): string
    {
        return sprintf(
            'DO %1$s BEGIN IF to_regclass(%2$s) IS NOT NULL THEN IF has_table_privilege(%2$s, \'SELECT\') AND has_table_privilege(%2$s, \'DELETE\') THEN DELETE FROM %3$s WHERE index_name = %4$s AND doc_id = \'*\' AND queued_at <= %5$s::timestamptz; END IF; END IF; IF EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass(%6$s) AND attname = \'rebuild_failures\' AND NOT attisdropped) THEN IF has_table_privilege(%6$s, \'SELECT\') AND has_table_privilege(%6$s, \'UPDATE\') THEN UPDATE %7$s SET rebuild_failed_at = NULL, rebuild_failures = NULL, rebuild_error = NULL WHERE index_name = %4$s AND rebuild_failures IS NOT NULL; END IF; END IF; END %1$s',
            self::TAG,
            Sql::string($this->names->queue()),
            $this->names->queue(),
            Sql::string($index->name),
            Sql::string($started),
            Sql::string($this->names->meta()),
            $this->names->meta(),
        );
    }

    private function syncFunction(IndexDefinition $index, Watch $watch): string
    {
        $columns = $this->relevantColumns($index, $watch);
        // First, so a TRUNCATE never reaches the NEW / OLD references below.
        $body = [$this->truncateBranch($index, $watch)];
        if ($columns !== null) {
            // The key column must always be treated as relevant: even when it's not itself a
            // field/filter/boost/recency column, a key-column UPDATE moves the row's identity out
            // from under the old document and in under a new one, and both refreshes are required.
            $diff = implode(' OR ', array_map(
                static fn(string $c): string => sprintf('NEW.%1$s IS DISTINCT FROM OLD.%1$s', Sql::ident($c)),
                array_unique([...$columns, $watch->keyColumn]),
            ));
            $body[] = sprintf("    IF TG_OP = 'UPDATE' AND NOT (%s) THEN\n        RETURN NULL;\n    END IF;", $diff);
        }
        foreach (['NEW' => "TG_OP <> 'DELETE'", 'OLD' => "TG_OP <> 'INSERT'"] as $record => $condition) {
            $affected = (string) preg_replace('/:id\b/', $record . '.' . Sql::ident($watch->keyColumn), $watch->affectedIds, 1);
            $action = $index->sync === SyncMode::Trigger
                ? sprintf(
                    'PERFORM %s(ARRAY(SELECT a.doc_id::%s FROM (%s) AS a(doc_id) WHERE a.doc_id IS NOT NULL));',
                    $this->names->refreshFunction($index),
                    Types::id($index->idType),
                    $affected,
                )
                : sprintf(
                    "INSERT INTO %s (index_name, doc_id)\n        SELECT %s, a.doc_id::text FROM (%s) AS a(doc_id) WHERE a.doc_id IS NOT NULL\n        ON CONFLICT (index_name, doc_id) DO NOTHING;",
                    $this->names->queue(),
                    Sql::string($index->name),
                    $affected,
                );
            $body[] = sprintf("    IF %s THEN\n        %s\n    END IF;", $condition, $action);
        }

        return sprintf(
            "CREATE OR REPLACE FUNCTION %s() RETURNS trigger\nLANGUAGE plpgsql SET search_path FROM CURRENT AS " . self::TAG . "\nBEGIN\n%s\n    RETURN NULL;\nEND\n" . self::TAG,
            $this->names->syncFunction($index, $watch),
            implode("\n", $body),
        );
    }

    private function statementSyncFunction(IndexDefinition $index, Watch $watch): string
    {
        $columns = $this->relevantColumns($index, $watch);
        $body = $columns === null
            ? $this->statementBodyUnfiltered($index, $watch)
            : $this->statementBodyFiltered($index, $watch, $columns);

        // The TRUNCATE branch comes first, so a TRUNCATE never reaches a transition table (none is registered for it).
        return sprintf(
            "CREATE OR REPLACE FUNCTION %s() RETURNS trigger\nLANGUAGE plpgsql SET search_path FROM CURRENT AS " . self::TAG . "\nBEGIN\n%s\n%s\n    RETURN NULL;\nEND\n" . self::TAG,
            $this->names->syncFunction($index, $watch),
            $this->truncateBranch($index, $watch),
            $body,
        );
    }

    /**
     * The TRUNCATE branch of both trigger levels. A TRUNCATE invocation has no NEW / OLD row and
     * no transition table, so this branch never references them and returns right away.
     *
     * - The index's own source table was truncated and the source really is empty now: the index
     *   is emptied too (and, in queue mode, whatever it still had queued is dropped). The emptiness
     *   is checked, not assumed: TRUNCATE ONLY on a table-inheritance parent fires this trigger while
     *   "SELECT * FROM parent" still returns the child tables' rows. Queue rows another transaction
     *   holds (a running worker) are skipped, so this never waits on the worker's row locks; the
     *   worker refreshes those ids itself and the refresh deletes them from the now empty index.
     *   While a full reindex runs, the queue is kept: the worker's refreshes log the ids for it.
     * - Any other watched table (or a source that is not empty): its rows are gone, so the affected
     *   documents cannot be told apart. Trigger mode resyncs every document that is indexed or that
     *   the source now returns, inside the truncating transaction (expensive on a big index). Queue
     *   mode queues one full-rebuild job, the queue row (index, '*'), which the worker runs next to
     *   the live index; processQueue() never takes it. A TRUNCATE while it is queued moves its
     *   queued_at on (the statement's clock, not the transaction's): a full run completes only a
     *   job queued before it started (completeRebuildRequest()).
     */
    private function truncateBranch(IndexDefinition $index, Watch $watch): string
    {
        $sidecar = $this->names->sidecar($index);
        $ids = sprintf(
            'SELECT s.id FROM %s AS s UNION SELECT doc.fz_id::%s FROM (%s) AS doc WHERE doc.fz_id IS NOT NULL',
            $sidecar,
            Types::id($index->idType),
            DocumentSql::select($index),
        );
        $resync = $index->sync === SyncMode::Trigger
            ? sprintf('PERFORM %s(ARRAY(%s));', $this->names->refreshFunction($index), $ids)
            : sprintf(
                "INSERT INTO %s (index_name, doc_id, queued_at) VALUES (%s, '*', clock_timestamp())
        ON CONFLICT (index_name, doc_id) DO UPDATE SET queued_at = clock_timestamp();",
                $this->names->queue(),
                Sql::string($index->name),
            );
        if ($index->source->table === null || $watch->table !== $index->source->table) {
            return sprintf("    IF TG_OP = 'TRUNCATE' THEN
        %s
        RETURN NULL;
    END IF;", $resync);
        }

        $wipe = [sprintf('DELETE FROM %s;', $sidecar)];
        if ($index->sync === SyncMode::Queue) {
            $wipe[] = sprintf(
                // not while a full reindex runs: the worker refreshes those ids (which logs them), a
                // document the rebuild loaded from the queued changes would otherwise survive the swap
                'IF to_regclass(%3$s) IS NULL THEN DELETE FROM %1$s WHERE ctid IN (SELECT ctid FROM %1$s WHERE index_name = %2$s FOR UPDATE SKIP LOCKED); END IF;',
                $this->names->queue(),
                Sql::string($index->name),
                Sql::string($this->names->changes($index)),
            );
        }

        return sprintf(
            "    IF TG_OP = 'TRUNCATE' THEN
        IF NOT EXISTS (SELECT 1 FROM %s) THEN
            %s
        ELSE
            %s
        END IF;
        RETURN NULL;
    END IF;",
            Sql::ident($index->source->table),
            implode("
            ", $wipe),
            str_replace("
        ", "
            ", $resync),
        );
    }

    /** Unfiltered: today's two combined-condition branches, unchanged — byte-identical output. */
    private function statementBodyUnfiltered(IndexDefinition $index, Watch $watch): string
    {
        $body = [];
        foreach (['fz_new' => "TG_OP IN ('INSERT', 'UPDATE')", 'fz_old' => "TG_OP IN ('UPDATE', 'DELETE')"] as $rows => $condition) {
            $affected = (string) preg_replace('/:id\b/', 'r.' . Sql::ident($watch->keyColumn), $watch->affectedIds, 1);
            $source = sprintf('%s AS r CROSS JOIN LATERAL (%s) AS a(doc_id)', $rows, $affected);
            $body[] = sprintf("    IF %s THEN\n        %s\n    END IF;", $condition, $this->statementAction($index, $source));
        }

        return implode("\n", $body);
    }

    /**
     * Filtered: three mutually exclusive branches (INSERT / UPDATE / DELETE), so a query naming
     * both transition tables is only ever reached during the _upd trigger invocation, where both
     * are actually registered. The UPDATE branch runs twice (once from the new row's perspective,
     * once from the old row's), matching the unfiltered version's existing dual computation; each
     * is restricted via a LEFT JOIN to rows whose relevant columns changed, or whose correlating
     * key has no match on the other side at all (conservatively treated as changed, rather than
     * silently dropped as an inner join would).
     *
     * @param list<string> $columns
     */
    private function statementBodyFiltered(IndexDefinition $index, Watch $watch, array $columns): string
    {
        $key = Sql::ident($watch->keyColumn);
        $body = [];
        $body[] = sprintf("    IF TG_OP = 'INSERT' THEN\n        %s\n    END IF;", $this->statementAction($index, $this->statementSource($watch, 'fz_new', 'r')));
        $body[] = sprintf("    IF TG_OP = 'DELETE' THEN\n        %s\n    END IF;", $this->statementAction($index, $this->statementSource($watch, 'fz_old', 'r')));

        $diffNew = implode(' OR ', array_map(static fn(string $c): string => sprintf('r.%1$s IS DISTINCT FROM o.%1$s', Sql::ident($c)), $columns));
        $diffOld = implode(' OR ', array_map(static fn(string $c): string => sprintf('r.%1$s IS DISTINCT FROM n.%1$s', Sql::ident($c)), $columns));
        // Both perspectives correlate on the same key, from the same "r" alias, so the affected-ids
        // expression itself (not just the key column) is identical either way.
        $affected = (string) preg_replace('/:id\b/', 'r.' . $key, $watch->affectedIds, 1);
        $updateNewSource = sprintf('fz_new AS r LEFT JOIN fz_old o ON o.%1$s = r.%1$s CROSS JOIN LATERAL (%2$s) AS a(doc_id)', $key, $affected);
        $updateOldSource = sprintf('fz_old AS r LEFT JOIN fz_new n ON n.%1$s = r.%1$s CROSS JOIN LATERAL (%2$s) AS a(doc_id)', $key, $affected);
        $body[] = sprintf(
            "    IF TG_OP = 'UPDATE' THEN\n        %s\n        %s\n    END IF;",
            $this->statementAction($index, $updateNewSource, sprintf('o.%1$s IS NULL OR (%2$s)', $key, $diffNew)),
            $this->statementAction($index, $updateOldSource, sprintf('n.%1$s IS NULL OR (%2$s)', $key, $diffOld)),
        );

        return implode("\n", $body);
    }

    private function statementSource(Watch $watch, string $transitionTable, string $alias): string
    {
        $affected = (string) preg_replace('/:id\b/', $alias . '.' . Sql::ident($watch->keyColumn), $watch->affectedIds, 1);

        return sprintf('%s AS %s CROSS JOIN LATERAL (%s) AS a(doc_id)', $transitionTable, $alias, $affected);
    }

    private function statementAction(IndexDefinition $index, string $source, ?string $extraWhere = null): string
    {
        $where = $extraWhere !== null ? sprintf('a.doc_id IS NOT NULL AND (%s)', $extraWhere) : 'a.doc_id IS NOT NULL';

        return $index->sync === SyncMode::Trigger
            ? sprintf(
                'PERFORM %s(ARRAY(SELECT DISTINCT a.doc_id::%s FROM %s WHERE %s));',
                $this->names->refreshFunction($index),
                Types::id($index->idType),
                $source,
                $where,
            )
            : sprintf(
                "INSERT INTO %s (index_name, doc_id)\n        SELECT DISTINCT %s, a.doc_id::text FROM %s WHERE %s\n        ON CONFLICT (index_name, doc_id) DO NOTHING;",
                $this->names->queue(),
                Sql::string($index->name),
                $source,
                $where,
            );
    }

    /** The dictionary that stems (and, for most languages, drops stop words) in the configuration. */
    public function stemDictionaryName(TextConfig $config): string
    {
        return $config->language === 'simple' ? 'simple' : $config->language . '_stem';
    }

    /**
     * unaccent is a filtering dictionary: the stem dictionary after it checks its stop-word list
     * against the unaccented token, so accented stop words ("für", "és", "à") would survive. A
     * stop-word-only dictionary (ACCEPT = false: drops a stop word, passes everything else on)
     * therefore runs first, with the stem dictionary's own list, read from the catalog. Languages
     * whose stem dictionary has no list get none. The mapping is always (re)applied, so running
     * this again repairs a configuration created without the stop-word dictionary.
     */
    private function textConfig(TextConfig $config): Statement
    {
        $name = $this->names->textConfigName($config);
        $stop = $this->names->stopDictionaryName($config);

        return new Statement(sprintf(
            <<<'SQL'
                DO %8$s
                DECLARE
                    v_stopwords text;
                BEGIN
                    IF NOT EXISTS (SELECT 1 FROM pg_ts_config c JOIN pg_namespace n ON n.oid = c.cfgnamespace WHERE c.cfgname = %1$s AND n.nspname = %10$s) THEN
                        CREATE TEXT SEARCH CONFIGURATION %2$s (COPY = %3$s);
                    END IF;
                    SELECT substring(dictinitoption FROM 'stopwords *= *''([[:alnum:]_]+)''') INTO v_stopwords
                    FROM pg_ts_dict WHERE oid = %5$s::regdictionary;
                    IF v_stopwords IS NULL THEN
                        ALTER TEXT SEARCH CONFIGURATION %2$s
                            ALTER MAPPING FOR hword, hword_part, word WITH %4$s.unaccent, %6$s;
                    ELSE
                        IF NOT EXISTS (SELECT 1 FROM pg_ts_dict d JOIN pg_namespace n ON n.oid = d.dictnamespace WHERE d.dictname = %7$s AND n.nspname = %10$s) THEN
                            EXECUTE format('CREATE TEXT SEARCH DICTIONARY %%I.%%I (TEMPLATE = pg_catalog.simple, STOPWORDS = %%L, ACCEPT = false)', %10$s, %7$s, v_stopwords);
                        END IF;
                        ALTER TEXT SEARCH CONFIGURATION %2$s
                            ALTER MAPPING FOR hword, hword_part, word WITH %9$s, %4$s.unaccent, %6$s;
                    END IF;
                END
                %8$s
                SQL,
            Sql::string($name),
            $this->names->textConfig($config),
            Sql::ident($config->language),
            $this->names->extension(),
            Sql::string(Sql::ident($this->stemDictionaryName($config))),
            Sql::ident($this->stemDictionaryName($config)),
            Sql::string($stop),
            self::TAG,
            $this->names->stopDictionary($config),
            Sql::string($this->names->schema),
        ), sprintf('Text search configuration "%s" (%s stemming + accent folding, accented stop words dropped)', $name, $config->language));
    }

    private function tsvectorExpression(IndexDefinition $index): string
    {
        return implode("\n            || ", array_map(
            fn(FieldDefinition $field): string => $this->fieldVectorExpression($index, $field),
            $index->fields,
        ));
    }

    /** One field's part of tsv, and its own t_<field> column. */
    private function fieldVectorExpression(IndexDefinition $index, FieldDefinition $field): string
    {
        return sprintf(
            "setweight(to_tsvector(%s, coalesce(doc.%s::text, '')), '%s')",
            $this->names->regconfig($index->text),
            Sql::ident('fld_' . $field->name),
            $field->weight->value,
        );
    }

    private function fuzzyExpression(IndexDefinition $index): string
    {
        $fields = $index->fuzzyFields();
        if ($fields === []) {
            return "''";
        }

        return sprintf("coalesce(concat_ws(' ', %s), '')", implode(', ', array_map($this->fieldFuzzyExpression(...), $fields)));
    }

    /** One fuzzy field's normalised text: its part of fz, and (coalesced) its own z_<field> column. */
    private function fieldFuzzyExpression(FieldDefinition $field): string
    {
        return sprintf('%s(doc.%s::text)', $this->names->normFunction(), Sql::ident('fld_' . $field->name));
    }

    private function exactExpression(IndexDefinition $index): string
    {
        return sprintf("coalesce(%s(doc.%s::text), '')", $this->names->normFunction(), Sql::ident('fld_' . $index->primaryField()->name));
    }
}
