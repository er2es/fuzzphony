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

        $definitions = array_map(static fn (string $name, string $type): string => sprintf('    %s %s', Sql::ident($name), $type), array_keys($columns), $columns);
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
        if ($index->triggerLevel === TriggerLevel::Row) {
            return [$function => ['timing' => 'AFTER INSERT OR UPDATE OR DELETE', 'for' => 'FOR EACH ROW']];
        }

        // Transition tables require one trigger per event.
        return [
            Identifier::limit($function . '_ins') => ['timing' => 'AFTER INSERT', 'for' => 'REFERENCING NEW TABLE AS fz_new FOR EACH STATEMENT'],
            Identifier::limit($function . '_upd') => ['timing' => 'AFTER UPDATE', 'for' => 'REFERENCING OLD TABLE AS fz_old NEW TABLE AS fz_new FOR EACH STATEMENT'],
            Identifier::limit($function . '_del') => ['timing' => 'AFTER DELETE', 'for' => 'REFERENCING OLD TABLE AS fz_old FOR EACH STATEMENT'],
        ];
    }

    /** @return list<string> every trigger name this watch can ever have */
    public function allTriggerNames(IndexDefinition $index, Watch $watch): array
    {
        $function = $this->syncFunctionName($index, $watch);

        return [$function, Identifier::limit($function . '_ins'), Identifier::limit($function . '_upd'), Identifier::limit($function . '_del')];
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
            static fn (string $c): string => sprintf('%1$s = EXCLUDED.%1$s', Sql::ident($c)),
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
        $body = [];
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

    /** Statement-level variant: one set-based INSERT / refresh per statement via transition tables. */
    private function statementSyncFunction(IndexDefinition $index, Watch $watch): string
    {
        $body = [];
        foreach (['fz_new' => "TG_OP IN ('INSERT', 'UPDATE')", 'fz_old' => "TG_OP IN ('UPDATE', 'DELETE')"] as $rows => $condition) {
            $affected = (string) preg_replace('/:id\b/', 'r.' . Sql::ident($watch->keyColumn), $watch->affectedIds, 1);
            $source = sprintf('%s AS r CROSS JOIN LATERAL (%s) AS a(doc_id)', $rows, $affected);
            $action = $index->sync === SyncMode::Trigger
                ? sprintf(
                    'PERFORM %s(ARRAY(SELECT DISTINCT a.doc_id::%s FROM %s WHERE a.doc_id IS NOT NULL));',
                    Sql::ident($this->refreshFunctionName($index)),
                    $index->idType->sqlType(),
                    $source,
                )
                : sprintf(
                    "INSERT INTO %s (index_name, doc_id)\n        SELECT DISTINCT %s, a.doc_id::text FROM %s WHERE a.doc_id IS NOT NULL\n        ON CONFLICT (index_name, doc_id) DO NOTHING;",
                    self::QUEUE_TABLE,
                    Sql::string($index->name),
                    $source,
                );
            $body[] = sprintf("    IF %s THEN\n        %s\n    END IF;", $condition, $action);
        }

        return sprintf(
            "CREATE OR REPLACE FUNCTION %s() RETURNS trigger\nLANGUAGE plpgsql AS \$fuzzphony\$\nBEGIN\n%s\n    RETURN NULL;\nEND\n\$fuzzphony\$",
            Sql::ident($this->syncFunctionName($index, $watch)),
            implode("\n", $body),
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
            static fn ($field): string => sprintf(
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
            static fn ($field): string => sprintf('%s(doc.%s::text)', self::NORM_FUNCTION, Sql::ident('fld_' . $field->name)),
            $fields,
        )));
    }

    private function exactExpression(IndexDefinition $index): string
    {
        return sprintf("coalesce(%s(doc.%s::text), '')", self::NORM_FUNCTION, Sql::ident('fld_' . $index->primaryField()->name));
    }
}
