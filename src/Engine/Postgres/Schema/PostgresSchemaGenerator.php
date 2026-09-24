<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\TextConfig;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Core\Support\Identifier;
use Fuzzphony\Engine\Postgres\Sql\DocumentSql;
use Fuzzphony\Engine\Postgres\Sql\FilterCompiler;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * Generates idempotent DDL. Nothing here ever alters the source tables, except for
 * (optional) AFTER triggers that feed the sync queue.
 */
final class PostgresSchemaGenerator
{
    public const string QUEUE_TABLE = 'fuzzphony_queue';
    public const string NORM_FUNCTION = 'fuzzphony_norm';

    public function __construct(private readonly string $extensionSchema = 'public')
    {
        if (!Identifier::isColumn($extensionSchema)) {
            throw new \InvalidArgumentException(sprintf('Invalid extension schema "%s".', $extensionSchema));
        }
    }

    public function global(IndexDefinition ...$indexes): SchemaPlan
    {
        $schema = $this->extensionSchema;
        $statements = [
            new Statement(sprintf('CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA %s', Sql::ident($schema)), 'Trigram matching for typo tolerance'),
            new Statement(sprintf('CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA %s', Sql::ident($schema)), 'Accent folding'),
            new Statement(sprintf(
                "CREATE OR REPLACE FUNCTION %s(text) RETURNS text\nLANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT\nAS \$fuzzphony\$ SELECT btrim(regexp_replace(lower(%s.unaccent('%s.unaccent'::regdictionary, \$1)), '[^[:alnum:]]+', ' ', 'g')) \$fuzzphony\$",
                self::NORM_FUNCTION,
                Sql::ident($schema),
                $schema,
            ), 'Normaliser for trigram / exact matching: lowercase, no accents, alphanumerics only'),
            new Statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s (\n    index_name text NOT NULL,\n    doc_id text NOT NULL,\n    queued_at timestamptz NOT NULL DEFAULT clock_timestamp(),\n    PRIMARY KEY (index_name, doc_id)\n)",
                self::QUEUE_TABLE,
            ), 'Sync queue shared by all indexes'),
            new Statement(sprintf('CREATE INDEX IF NOT EXISTS fuzzphony_queue_order ON %s (index_name, queued_at)', self::QUEUE_TABLE), 'Queue processing order'),
        ];

        $configs = [];
        foreach ($indexes as $index) {
            if ($index->text->unaccent) {
                $configs[$index->text->configName()] = $index->text;
            }
        }
        foreach ($configs as $config) {
            $statements[] = $this->textConfig($config);
        }

        return new SchemaPlan($statements);
    }

    public function index(IndexDefinition $index): SchemaPlan
    {
        $table = Sql::ident($index->sidecarTable());
        $columns = $this->columns($index);

        $definitions = array_map(static fn(string $name, string $type): string => sprintf('    %s %s', Sql::ident($name), $type), array_keys($columns), $columns);
        $statements = [
            new Statement(sprintf("CREATE TABLE IF NOT EXISTS %s (\n%s\n)", $table, implode(",\n", $definitions)), sprintf('Sidecar index table of "%s"', $index->name)),
        ];
        foreach (array_slice($columns, 1, null, true) as $name => $type) {
            $statements[] = new Statement(
                sprintf('ALTER TABLE %s ADD COLUMN IF NOT EXISTS %s %s', $table, Sql::ident($name), $type),
                sprintf('Keep column "%s" in sync with the definition', $name),
            );
        }
        $statements[] = new Statement($this->refreshFunction($index), 'Builds / removes documents by id');

        foreach ($index->effectiveWatches() as $watch) {
            $function = $this->syncFunctionName($index, $watch);
            $watched = Sql::ident($watch->table);
            foreach ($this->obsoleteTriggerNames($index, $watch) as $obsolete) {
                $statements[] = new Statement(sprintf('DROP TRIGGER IF EXISTS %s ON %s', Sql::ident($obsolete), $watched), sprintf('Remove trigger not used in "%s" sync / %s level', $index->sync->value, $index->triggerLevel->value));
            }
            if (!$index->sync->usesTriggers()) {
                continue;
            }
            $statements[] = new Statement(
                $index->triggerLevel === TriggerLevel::Statement ? $this->statementSyncFunction($index, $watch) : $this->syncFunction($index, $watch),
                sprintf('Sync trigger function for %s', $watch->table),
            );
            foreach ($this->triggerDefinitions($index, $watch) as $name => $definition) {
                $statements[] = new Statement(
                    sprintf('CREATE OR REPLACE TRIGGER %s %s ON %s %s EXECUTE FUNCTION %s()', Sql::ident($name), $definition['timing'], $watched, $definition['for'], Sql::ident($function)),
                    sprintf('%s sync on %s (%s level)', ucfirst($index->sync->value), $watch->table, $index->triggerLevel->value),
                );
            }
        }

        foreach ($this->indexes($index) as $name => $definition) {
            $statements[] = new Statement(
                sprintf('CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s %s', Sql::ident($name), $table, $definition),
                sprintf('Index %s', $name),
                transactional: false,
            );
        }

        return new SchemaPlan($statements);
    }

    public function drop(IndexDefinition $index): SchemaPlan
    {
        $statements = [];
        foreach ($index->effectiveWatches() as $watch) {
            $function = $this->syncFunctionName($index, $watch);
            foreach ($this->allTriggerNames($index, $watch) as $trigger) {
                $statements[] = new Statement(sprintf('DROP TRIGGER IF EXISTS %s ON %s', Sql::ident($trigger), Sql::ident($watch->table)), 'Remove sync trigger');
            }
            $statements[] = new Statement(sprintf('DROP FUNCTION IF EXISTS %s()', Sql::ident($function)), 'Remove sync function');
        }
        $statements[] = new Statement(sprintf('DROP FUNCTION IF EXISTS %s(%s[])', Sql::ident($this->refreshFunctionName($index)), $index->idType->sqlType()), 'Remove refresh function');
        $statements[] = new Statement(sprintf('DROP TABLE IF EXISTS %s', Sql::ident($index->sidecarTable())), 'Remove sidecar table');
        $statements[] = new Statement(sprintf(
            "DO \$fuzzphony\$ BEGIN IF to_regclass('%s') IS NOT NULL THEN DELETE FROM %s WHERE index_name = %s; END IF; END \$fuzzphony\$",
            self::QUEUE_TABLE,
            self::QUEUE_TABLE,
            Sql::string($index->name),
        ), 'Forget queued items');

        return new SchemaPlan($statements);
    }

    /**
     * Expected sidecar columns (name => type); the first one is the primary key.
     *
     * @return array<string, string>
     */
    public function columns(IndexDefinition $index): array
    {
        $columns = [
            'id' => $index->idType->sqlType() . ' PRIMARY KEY',
            'tsv' => 'tsvector NOT NULL',
            'fz' => "text NOT NULL DEFAULT ''",
            'exact' => "text NOT NULL DEFAULT ''",
        ];
        if ($index->boostColumn !== null) {
            $columns['boost'] = 'double precision';
        }
        if ($index->recencyColumn !== null) {
            $columns['recency_at'] = 'timestamptz';
        }
        foreach ($index->filters as $filter) {
            $columns['f_' . $filter->name] = $filter->type->sqlType();
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
        $sidecar = $index->sidecarTable();
        $indexes = [Identifier::limit($sidecar . '_tsv') => 'USING gin (tsv)'];
        if ($index->hasFuzzy()) {
            $indexes[Identifier::limit($sidecar . '_fz')] = sprintf('USING gin (fz %s.gin_trgm_ops)', Sql::ident($this->extensionSchema));
        }
        foreach ($index->filters as $filter) {
            $indexes[Identifier::limit($sidecar . '_f_' . $filter->name)] = sprintf('(%s)', FilterCompiler::column($filter->name));
        }

        return $indexes;
    }

    public function refreshFunctionName(IndexDefinition $index): string
    {
        return Identifier::limit('fuzzphony_refresh_' . $index->name);
    }

    public function syncFunctionName(IndexDefinition $index, Watch $watch): string
    {
        return Identifier::limit('fuzzphony_sync_' . $index->name . '__' . str_replace('.', '_', $watch->table));
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
        $function = $this->syncFunctionName($index, $watch);
        // PostgreSQL never runs DELETE triggers for TRUNCATE and only allows TRUNCATE triggers per
        // statement (without transition tables), so both levels get the same extra trigger.
        $truncate = [Identifier::limit($function . '_trn') => ['timing' => 'AFTER TRUNCATE', 'for' => 'FOR EACH STATEMENT']];
        if ($index->triggerLevel === TriggerLevel::Row) {
            return [$function => ['timing' => 'AFTER INSERT OR UPDATE OR DELETE', 'for' => 'FOR EACH ROW']] + $truncate;
        }

        // Transition tables require one trigger per event.
        return [
            Identifier::limit($function . '_ins') => ['timing' => 'AFTER INSERT', 'for' => 'REFERENCING NEW TABLE AS fz_new FOR EACH STATEMENT'],
            Identifier::limit($function . '_upd') => ['timing' => 'AFTER UPDATE', 'for' => 'REFERENCING OLD TABLE AS fz_old NEW TABLE AS fz_new FOR EACH STATEMENT'],
            Identifier::limit($function . '_del') => ['timing' => 'AFTER DELETE', 'for' => 'REFERENCING OLD TABLE AS fz_old FOR EACH STATEMENT'],
        ] + $truncate;
    }

    /** @return list<string> every trigger name this watch can ever have */
    public function allTriggerNames(IndexDefinition $index, Watch $watch): array
    {
        $function = $this->syncFunctionName($index, $watch);

        return [
            $function,
            Identifier::limit($function . '_ins'),
            Identifier::limit($function . '_upd'),
            Identifier::limit($function . '_del'),
            Identifier::limit($function . '_trn'),
        ];
    }

    /** @return list<string> trigger names that must NOT exist for the current sync mode / level */
    public function obsoleteTriggerNames(IndexDefinition $index, Watch $watch): array
    {
        return array_values(array_diff($this->allTriggerNames($index, $watch), array_keys($this->triggerDefinitions($index, $watch))));
    }

    private function refreshFunction(IndexDefinition $index): string
    {
        $table = Sql::ident($index->sidecarTable());
        $columns = ['id', 'tsv', 'fz', 'exact'];
        $values = ['doc.fz_id', $this->tsvectorExpression($index), $this->fuzzyExpression($index), $this->exactExpression($index)];
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
            $values[] = sprintf('doc.%s::%s', Sql::ident('flt_' . $filter->name), $filter->type === FilterType::Int ? 'bigint' : $filter->type->sqlType());
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
                LANGUAGE plpgsql AS $fuzzphony$
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
                      AND NOT EXISTS (SELECT 1 FROM (%6$s) AS doc WHERE doc.fz_id = s.id);

                    RETURN written;
                END
                $fuzzphony$
                SQL,
            Sql::ident($this->refreshFunctionName($index)),
            $index->idType->sqlType(),
            $table,
            implode(', ', array_map(Sql::ident(...), $columns)),
            implode(",\n        ", $values),
            $document,
            $updates,
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
                    Sql::ident($this->refreshFunctionName($index)),
                    $index->idType->sqlType(),
                    $affected,
                )
                : sprintf(
                    "INSERT INTO %s (index_name, doc_id)\n        SELECT %s, a.doc_id::text FROM (%s) AS a(doc_id) WHERE a.doc_id IS NOT NULL\n        ON CONFLICT (index_name, doc_id) DO NOTHING;",
                    self::QUEUE_TABLE,
                    Sql::string($index->name),
                    $affected,
                );
            $body[] = sprintf("    IF %s THEN\n        %s\n    END IF;", $condition, $action);
        }

        return sprintf(
            "CREATE OR REPLACE FUNCTION %s() RETURNS trigger\nLANGUAGE plpgsql AS \$fuzzphony\$\nBEGIN\n%s\n    RETURN NULL;\nEND\n\$fuzzphony\$",
            Sql::ident($this->syncFunctionName($index, $watch)),
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
            "CREATE OR REPLACE FUNCTION %s() RETURNS trigger\nLANGUAGE plpgsql AS \$fuzzphony\$\nBEGIN\n%s\n%s\n    RETURN NULL;\nEND\n\$fuzzphony\$",
            Sql::ident($this->syncFunctionName($index, $watch)),
            $this->truncateBranch($index, $watch),
            $body,
        );
    }

    /**
     * The TRUNCATE branch of both trigger levels. A TRUNCATE invocation has no NEW / OLD row and
     * no transition table, so this branch never references them and returns right away.
     *
     * - The index's own source table was truncated: the source is empty, so the index is emptied
     *   too (and, in queue mode, whatever it still had queued is dropped).
     * - Any other watched table: its rows are gone, so the affected documents cannot be told
     *   apart; every document that is indexed or that the source now returns is resynced.
     *   Expensive on a big index, but a TRUNCATE is rare.
     */
    private function truncateBranch(IndexDefinition $index, Watch $watch): string
    {
        $sidecar = Sql::ident($index->sidecarTable());
        if ($index->source->table !== null && $watch->table === $index->source->table) {
            $actions = [sprintf('DELETE FROM %s;', $sidecar)];
            if ($index->sync === SyncMode::Queue) {
                $actions[] = sprintf('DELETE FROM %s WHERE index_name = %s;', self::QUEUE_TABLE, Sql::string($index->name));
            }
        } else {
            $ids = sprintf(
                'SELECT s.id FROM %s AS s UNION SELECT doc.fz_id::%s FROM (%s) AS doc WHERE doc.fz_id IS NOT NULL',
                $sidecar,
                $index->idType->sqlType(),
                DocumentSql::select($index),
            );
            $actions = [$index->sync === SyncMode::Trigger
                ? sprintf('PERFORM %s(ARRAY(%s));', Sql::ident($this->refreshFunctionName($index)), $ids)
                : sprintf(
                    "INSERT INTO %s (index_name, doc_id)\n        SELECT %s, t.id::text FROM (%s) AS t(id)\n        ON CONFLICT (index_name, doc_id) DO NOTHING;",
                    self::QUEUE_TABLE,
                    Sql::string($index->name),
                    $ids,
                )];
        }

        return sprintf("    IF TG_OP = 'TRUNCATE' THEN\n        %s\n        RETURN NULL;\n    END IF;", implode("\n        ", $actions));
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
                Sql::ident($this->refreshFunctionName($index)),
                $index->idType->sqlType(),
                $source,
                $where,
            )
            : sprintf(
                "INSERT INTO %s (index_name, doc_id)\n        SELECT DISTINCT %s, a.doc_id::text FROM %s WHERE %s\n        ON CONFLICT (index_name, doc_id) DO NOTHING;",
                self::QUEUE_TABLE,
                Sql::string($index->name),
                $source,
                $where,
            );
    }

    private function textConfig(TextConfig $config): Statement
    {
        $name = $config->configName();
        $dictionary = $config->language === 'simple' ? 'simple' : $config->language . '_stem';

        return new Statement(sprintf(
            <<<'SQL'
                DO $fuzzphony$
                BEGIN
                    IF NOT EXISTS (SELECT 1 FROM pg_ts_config WHERE cfgname = %1$s) THEN
                        CREATE TEXT SEARCH CONFIGURATION %2$s (COPY = %3$s);
                        ALTER TEXT SEARCH CONFIGURATION %2$s
                            ALTER MAPPING FOR hword, hword_part, word WITH %4$s.unaccent, %5$s;
                    END IF;
                END
                $fuzzphony$
                SQL,
            Sql::string($name),
            Sql::ident($name),
            Sql::ident($config->language),
            Sql::ident($this->extensionSchema),
            Sql::ident($dictionary),
        ), sprintf('Text search configuration "%s" (%s stemming + accent folding)', $name, $config->language));
    }

    private function tsvectorExpression(IndexDefinition $index): string
    {
        $config = Sql::string($index->text->configName()) . '::regconfig';

        return implode("\n            || ", array_map(
            static fn($field): string => sprintf(
                "setweight(to_tsvector(%s, coalesce(doc.%s::text, '')), '%s')",
                $config,
                Sql::ident('fld_' . $field->name),
                $field->weight->value,
            ),
            $index->fields,
        ));
    }

    private function fuzzyExpression(IndexDefinition $index): string
    {
        $fields = $index->fuzzyFields();
        if ($fields === []) {
            return "''";
        }

        return sprintf("coalesce(concat_ws(' ', %s), '')", implode(', ', array_map(
            static fn($field): string => sprintf('%s(doc.%s::text)', self::NORM_FUNCTION, Sql::ident('fld_' . $field->name)),
            $fields,
        )));
    }

    private function exactExpression(IndexDefinition $index): string
    {
        return sprintf("coalesce(%s(doc.%s::text), '')", self::NORM_FUNCTION, Sql::ident('fld_' . $index->primaryField()->name));
    }
}
