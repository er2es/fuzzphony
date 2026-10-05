<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Inspection;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Inspection\InspectionReport;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Ranking\FuzzyMode;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Schema\Types;
use Fuzzphony\Engine\Postgres\Sql\DocumentSql;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal "fuzzphony:doctor": verifies that the database matches the definition and says how to fix
 * what does not. Read-only (the probe view is temporary and dropped immediately).
 */
final class PostgresInspector
{
    private const string APPLY = 'bin/console fuzzphony:schema --apply';

    private readonly Names $names;

    public function __construct(
        private readonly Connection $connection,
        private readonly PostgresSchemaGenerator $schema,
    ) {
        $this->names = $schema->names();
    }

    public function inspect(IndexDefinition $index, InspectOptions $options): InspectionReport
    {
        $checks = [];
        $checks[] = $this->version();
        array_push($checks, ...$this->extensions($index));
        $checks[] = $this->textConfig($index);
        $checks[] = $this->function($this->names->normFunction() . '(text)', 'Normaliser function');

        $sourceColumns = $this->sourceColumns($index, $checks);
        if ($sourceColumns !== null) {
            array_push($checks, ...$this->sourceMapping($index, $sourceColumns));
        }
        if ($index->source->table !== null) {
            $checks[] = $this->sourceKey($index);
        }

        $sidecarExists = $this->regclass($this->names->sidecar($index));
        if (!$sidecarExists) {
            $checks[] = Check::error('Sidecar table', sprintf('Table %s does not exist.', $this->names->sidecar($index)), self::APPLY);
            array_push($checks, ...$this->location($index));
        } else {
            array_push($checks, ...$this->sidecarColumns($index));
            array_push($checks, ...$this->sidecarIndexes($index));
            array_push($checks, ...$this->rebuild($index));
        }
        $checks[] = $this->function(
            sprintf('%s(%s[])', $this->names->refreshFunction($index), Types::id($index->idType)),
            'Refresh function',
        );
        $checks[] = $this->function(
            sprintf('%s(%s[])', $this->names->shadowRefreshFunction($index), Types::id($index->idType)),
            'Rebuild refresh function',
        );
        $checks[] = $this->function($this->names->trackFunction($index) . '()', 'Rebuild change log function');
        array_push($checks, ...$this->triggers($index));
        $checks[] = $this->queue($index, $options);
        if ($sidecarExists && $sourceColumns !== null) {
            $checks[] = $this->coverage($index, $options);
            $checks[] = $this->orphans($index, $options);
        }
        array_push($checks, ...$this->configuration($index));
        array_push($checks, ...$this->tenantScoping($index));
        array_push($checks, ...$this->columnAwareFiltering($index));
        array_push($checks, ...$this->schemaVersion($index)); // last: earlier checks keep their order

        return new InspectionReport($index->name, $checks);
    }

    /** @return list<Check> */
    private function schemaVersion(IndexDefinition $index): array
    {
        $exists = $this->regclass($this->names->meta());
        // the role, only when it may not read the table (reading it would fail the whole report)
        $denied = $exists
            ? $this->connection->fetchValue("SELECT current_user WHERE NOT has_table_privilege(:meta, 'SELECT')", ['meta' => $this->names->meta()])
            : null;
        if ($denied !== null) {
            $role = Coerce::str($denied);

            return [Check::warning(
                'Schema version',
                sprintf('Role %s cannot read %s (no SELECT privilege), so the version is unknown.', $role, $this->names->meta()),
                sprintf('GRANT SELECT ON %s TO %s;', $this->names->meta(), Sql::ident($role)),
            )];
        }
        $rows = [];
        if ($exists) {
            $found = $this->connection->fetchAll(
                sprintf("SELECT index_name, layout_version, definition_hash, documents_hash, library_version FROM %s WHERE index_name IN (:index, '*')", $this->names->meta()),
                ['index' => $index->name],
            );
            foreach ($found as $row) {
                $rows[Coerce::str($row['index_name'])] = $row;
            }
        }
        $checks = [$this->sharedObjects($rows['*'] ?? null)];
        $row = $rows[$index->name] ?? null;
        if ($row === null) {
            $checks[] = Check::warning('Schema version', 'No version record: built before 0.4, or never applied.', self::APPLY);

            return $checks;
        }
        $checks[] = $this->layout('Schema version', $row);
        $checks[] = Coerce::str($row['definition_hash']) === Fingerprint::definition($index)
            ? Check::ok('Definition', 'unchanged since the last apply')
            : Check::error('Definition', 'The definition changed since the last apply.', self::APPLY);
        $checks[] = Coerce::str($row['documents_hash']) === Fingerprint::documents($index)
            ? Check::ok('Documents', 'built from the current definition')
            : Check::warning('Documents', 'The documents were built from another definition, or not fully reindexed since 0.4.', sprintf('bin/console fuzzphony:reindex %s', $index->name));

        return $checks;
    }

    /**
     * The "*" row: the layout of the shared objects (queue, normaliser, text configurations) and
     * where they live (Fingerprint::shared()).
     *
     * @param array<string, mixed>|null $row
     */
    private function sharedObjects(?array $row): Check
    {
        if ($row === null) {
            return Check::warning('Shared objects', 'No version record for the shared objects: built before 0.4, or never applied.', self::APPLY);
        }
        $layout = $this->layout('Shared objects', $row);
        if ($layout->status !== CheckStatus::Ok) {
            return $layout;
        }

        return Coerce::str($row['definition_hash']) === Fingerprint::shared($this->names)
            ? $layout
            : Check::error('Shared objects', "Fuzzphony's schema or the extension schema changed since the last apply.", self::APPLY);
    }

    /** @param array<string, mixed> $row */
    private function layout(string $name, array $row): Check
    {
        $layout = Coerce::int($row['layout_version']);
        $current = PostgresSchemaGenerator::LAYOUT_VERSION;
        $by = Coerce::str($row['library_version']);

        return match (true) {
            $layout < $current => Check::error($name, sprintf('Layout %d is older than this library\'s layout %d.', $layout, $current), self::APPLY),
            $layout > $current => Check::error($name, sprintf('Layout %d was applied by a newer Fuzzphony (%s); this library knows layout %d.', $layout, $by, $current), sprintf('Upgrade fuzzphony/fuzzphony to %s or later.', $by)),
            default => Check::ok($name, sprintf('layout %d, applied by %s', $layout, $by)),
        };
    }

    private function version(): Check
    {
        $version = Coerce::int($this->connection->fetchValue("SELECT current_setting('server_version_num')::int"));

        return $version >= 150000
            ? Check::ok('PostgreSQL version', sprintf('%d.%d', intdiv($version, 10000), $version % 10000))
            : Check::error('PostgreSQL version', sprintf('PostgreSQL 15 or newer is required, found %d.', intdiv($version, 10000)));
    }

    /** @return list<Check> */
    private function extensions(IndexDefinition $index): array
    {
        $installed = array_column($this->connection->fetchAll("SELECT extname FROM pg_extension WHERE extname IN ('pg_trgm', 'unaccent')"), 'extname');
        $checks = [];
        foreach (['unaccent' => true, 'pg_trgm' => $index->hasFuzzy()] as $extension => $required) {
            if (in_array($extension, $installed, true)) {
                $checks[] = Check::ok('Extension ' . $extension, 'installed');
            } elseif ($required) {
                $checks[] = Check::error(
                    'Extension ' . $extension,
                    'not installed (a superuser or the database owner must create it once)',
                    sprintf('CREATE EXTENSION IF NOT EXISTS %s;', $extension),
                );
            } else {
                $checks[] = Check::skipped('Extension ' . $extension, 'not needed by this index');
            }
        }

        return $checks;
    }

    private function textConfig(IndexDefinition $index): Check
    {
        $label = $this->names->textConfig($index->text);
        $sql = 'SELECT count(*) > 0 FROM pg_ts_config c JOIN pg_namespace n ON n.oid = c.cfgnamespace WHERE c.cfgname = :name';
        $params = ['name' => $this->names->textConfigName($index->text)];
        if ($index->text->unaccent) {
            // Fuzzphony's own copy must be in Fuzzphony's schema; a built-in one may be anywhere
            $sql .= ' AND n.nspname = :schema';
            $params['schema'] = $this->names->schema;
        }

        if (!(bool) $this->connection->fetchValue($sql, $params)) {
            return Check::error('Text search configuration', sprintf('%s is missing.', $label), self::APPLY);
        }
        if ($index->text->unaccent && $this->keepsAccentedStopWords($index)) {
            return Check::error(
                'Text search configuration',
                sprintf('%s keeps accented stop words (such as "für", "és", "à"): the stop-word dictionary %s does not run before unaccent.', $label, $this->names->stopDictionary($index->text)),
                self::APPLY . sprintf(', then bin/console fuzzphony:reindex %s', $index->name),
            );
        }

        return Check::ok('Text search configuration', $label);
    }

    /** A 0.3.0 configuration: the stem dictionary has a stop-word list, but "word" tokens do not start with the stop-word dictionary. */
    private function keepsAccentedStopWords(IndexDefinition $index): bool
    {
        return (bool) $this->connection->fetchValue(
            <<<'SQL'
                SELECT EXISTS (SELECT 1 FROM pg_ts_dict WHERE dictname = :stem AND dictinitoption ~ 'stopwords')
                   AND NOT EXISTS (
                       SELECT 1
                       FROM pg_ts_config c
                       JOIN pg_namespace n ON n.oid = c.cfgnamespace
                       JOIN pg_ts_config_map m ON m.mapcfg = c.oid AND m.mapseqno = 1
                       JOIN pg_ts_dict d ON d.oid = m.mapdict
                       WHERE c.cfgname = :name AND n.nspname = :schema AND d.dictname = :stop
                         AND m.maptokentype = (SELECT t.tokid FROM ts_token_type(c.cfgparser) AS t WHERE t.alias = 'word')
                   )
                SQL,
            ['stem' => $this->schema->stemDictionaryName($index->text), 'name' => $this->names->textConfigName($index->text), 'stop' => $this->names->stopDictionaryName($index->text), 'schema' => $this->names->schema],
        );
    }

    /**
     * A dedicated schema that lacks this index while "public" still has it: an install from
     * before the "schema" setting, which is not moved automatically. (Only called when the
     * sidecar is missing, so with schema "public" the lookup below finds nothing either.)
     *
     * @return list<Check>
     */
    private function location(IndexDefinition $index): array
    {
        $legacy = Sql::ident('public.' . $this->names->sidecarName($index));
        if (!$this->regclass($legacy)) {
            return [];
        }

        return [Check::warning(
            'Schema',
            sprintf('%s has no sidecar table for this index, but %s exists: an install from before the dedicated schema.', $this->names->quotedSchema(), $legacy),
            'See UPGRADE.md, "Moving to a dedicated schema": fuzzphony:schema --apply, fuzzphony:reindex, then drop the old objects.',
        )];
    }

    private function function(string $signature, string $label): Check
    {
        $exists = (bool) $this->connection->fetchValue('SELECT to_regprocedure(:sig) IS NOT NULL', ['sig' => $signature]);

        return $exists ? Check::ok($label, $signature) : Check::error($label, sprintf('%s is missing.', $signature), self::APPLY);
    }

    /**
     * Columns (name => type) of the raw source, probed through a temporary view.
     *
     * @param list<Check> $checks
     *
     * @return array<string, string>|null
     */
    private function sourceColumns(IndexDefinition $index, array &$checks): ?array
    {
        $view = 'fuzzphony_probe_' . bin2hex(random_bytes(4));
        try {
            $this->connection->execute(sprintf('CREATE TEMPORARY VIEW %s AS %s', Sql::ident($view), DocumentSql::raw($index)));
            $rows = $this->connection->fetchAll(
                'SELECT attname, format_type(atttypid, atttypmod) AS type FROM pg_attribute WHERE attrelid = to_regclass(:view) AND attnum > 0 AND NOT attisdropped',
                ['view' => 'pg_temp.' . $view],
            );
        } catch (\Throwable $e) {
            $firstLine = strtok($e->getMessage(), "\n");
            $checks[] = Check::error(
                'Source',
                sprintf('The source cannot be queried: %s', trim($firstLine !== false ? $firstLine : $e->getMessage())),
                $index->source->table !== null ? sprintf('Check that table "%s" exists and is readable.', $index->source->table) : 'Run the source query manually and fix it.',
            );

            return null;
        } finally {
            try {
                $this->connection->execute(sprintf('DROP VIEW IF EXISTS %s', Sql::ident($view)));
            } catch (\Throwable) {
            }
        }

        $checks[] = Check::ok('Source', $index->source->table !== null ? sprintf('table "%s"', $index->source->table) : 'custom query');
        $columns = [];
        foreach ($rows as $row) {
            $columns[Coerce::str($row['attname'])] = (string) preg_replace('/\(.*\)/', '', Coerce::str($row['type']));
        }

        return $columns;
    }

    /**
     * @param array<string, string> $columns
     *
     * @return list<Check>
     */
    private function sourceMapping(IndexDefinition $index, array $columns): array
    {
        $checks = [];
        $known = implode(', ', array_keys($columns));

        $id = $index->source->idColumn;
        $idTypes = match ($index->idType) {
            IdType::Int => ['smallint', 'integer', 'bigint'],
            IdType::Uuid => ['uuid'],
            IdType::String => ['text', 'character varying', 'character'],
        };
        if (!isset($columns[$id])) {
            $checks[] = Check::error('Id column', sprintf('Column "%s" not found in the source. Available: %s.', $id, $known));
        } elseif (!in_array($columns[$id], $idTypes, true)) {
            $checks[] = Check::error('Id column', sprintf('"%s" is %s, but the index expects id type "%s".', $id, $columns[$id], $index->idType->value));
        } else {
            $checks[] = Check::ok('Id column', sprintf('%s (%s)', $id, $columns[$id]));
        }

        $missing = [];
        foreach ($index->fields as $field) {
            if (!isset($columns[$field->column()])) {
                $missing[] = sprintf('%s -> "%s"', $field->name, $field->column());
            }
        }
        $checks[] = $missing === []
            ? Check::ok('Field columns', sprintf('%d field(s) mapped', count($index->fields)))
            : Check::error('Field columns', sprintf('Missing in source: %s. Available: %s.', implode(', ', $missing), $known));

        foreach ($index->filters as $filter) {
            $type = $columns[$filter->column()] ?? null;
            $checks[] = match (true) {
                $type === null => Check::error('Filter ' . $filter->name, sprintf('Column "%s" not found in source.', $filter->column())),
                !in_array($type, Types::compatible($filter->type), true) => Check::warning(
                    'Filter ' . $filter->name,
                    sprintf('Column "%s" is %s; declared as "%s" (values are cast on indexing).', $filter->column(), $type, $filter->type->value),
                ),
                default => Check::ok('Filter ' . $filter->name, sprintf('%s (%s)', $filter->column(), $type)),
            };
        }

        foreach (['Boost column' => [$index->boostColumn, FilterType::Float], 'Recency column' => [$index->recencyColumn, FilterType::DateTime]] as $label => [$column, $type]) {
            if ($column === null) {
                continue;
            }
            $actual = $columns[$column] ?? null;
            $checks[] = match (true) {
                $actual === null => Check::error($label, sprintf('Column "%s" not found in source.', $column)),
                !in_array($actual, Types::compatible($type), true) => Check::error($label, sprintf('Column "%s" is %s; expected a %s type.', $column, $actual, $type === FilterType::Float ? 'numeric' : 'date/timestamp')),
                default => Check::ok($label, sprintf('%s (%s)', $column, $actual)),
            };
        }

        return $checks;
    }

    private function sourceKey(IndexDefinition $index): Check
    {
        $indexed = (bool) $this->connection->fetchValue(
            <<<'SQL'
                SELECT count(*) > 0
                FROM pg_index i
                JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0]
                WHERE i.indrelid = to_regclass(:table) AND (i.indisprimary OR i.indisunique) AND i.indnkeyatts = 1 AND a.attname = :column
                SQL,
            ['table' => (string) $index->source->table, 'column' => $index->source->idColumn],
        );

        return $indexed
            ? Check::ok('Source key', sprintf('"%s" is a primary/unique key', $index->source->idColumn))
            : Check::warning(
                'Source key',
                sprintf('"%s" is not a primary or unique key: refreshes will scan the table and duplicate ids are ambiguous.', $index->source->idColumn),
                sprintf('CREATE UNIQUE INDEX CONCURRENTLY ON %s (%s);', Sql::ident((string) $index->source->table), Sql::ident($index->source->idColumn)),
            );
    }

    /** @return list<Check> */
    private function sidecarColumns(IndexDefinition $index): array
    {
        $actual = array_map(Coerce::str(...), array_column($this->connection->fetchAll(
            'SELECT attname FROM pg_attribute WHERE attrelid = to_regclass(:table) AND attnum > 0 AND NOT attisdropped',
            ['table' => $this->names->sidecar($index)],
        ), 'attname'));
        $expected = array_keys($this->schema->columns($index));
        $missing = array_diff($expected, $actual);
        $extra = array_diff($actual, $expected);

        $checks = [];
        $checks[] = $missing === []
            ? Check::ok('Sidecar columns', sprintf('%d column(s) match the definition', count($expected)))
            : Check::error('Sidecar columns', sprintf('Schema drift, missing: %s.', implode(', ', $missing)), self::APPLY);
        if ($extra !== []) {
            $sidecar = $this->names->sidecar($index);
            $checks[] = Check::warning(
                'Sidecar columns',
                sprintf('Columns no longer in the definition: %s (harmless, but they waste space).', implode(', ', $extra)),
                implode(' ', array_map(static fn(string $c): string => sprintf('ALTER TABLE %s DROP COLUMN %s;', $sidecar, Sql::ident($c)), $extra)),
            );
        }

        return $checks;
    }

    /** @return list<Check> */
    private function sidecarIndexes(IndexDefinition $index): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT c.relname, i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE i.indrelid = to_regclass(:table)',
            ['table' => $this->names->sidecar($index)],
        );
        $valid = [];
        foreach ($rows as $row) {
            $valid[Coerce::str($row['relname'])] = (bool) $row['indisvalid'];
        }

        $checks = [];
        foreach ($this->schema->indexes($index) as $name => $definition) {
            $create = sprintf('CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s %s;', Sql::ident($name), $this->names->sidecar($index), $definition);
            $checks[] = match (true) {
                !array_key_exists($name, $valid) => Check::error('Index ' . $name, 'missing', $create),
                !$valid[$name] => Check::error('Index ' . $name, 'INVALID (an interrupted concurrent build); it is ignored by the planner', sprintf('DROP INDEX CONCURRENTLY %s; %s', Sql::ident($name), $create)),
                default => Check::ok('Index ' . $name, 'valid'),
            };
        }

        return $checks;
    }

    /**
     * A partitioned watched table: every partition, at every level, needs the TRUNCATE trigger (a
     * partition attached after the last apply lacks it), enabled. Statement-level triggers cannot
     * go on partitions, so at that level a write that targets a partition directly is not synced.
     * In every sync mode: a TRUNCATE trigger left on a table that should not have it (a detached
     * partition keeps its own; any partition in a mode without triggers), which the apply removes.
     * The watched table is quoted like the generator quotes it.
     *
     * @return list<Check>
     */
    private function partitions(IndexDefinition $index, Watch $watch): array
    {
        $trigger = $this->names->triggerName($index, $watch, '_trn');
        $table = Sql::ident($watch->table);
        $label = 'Partitions of ' . $watch->table;
        $checks = [];
        if ($index->sync->usesTriggers()) {
            $rows = $this->connection->fetchAll(
                'SELECT t.relid::regclass::text AS part, (SELECT g.tgenabled FROM pg_trigger AS g WHERE g.tgrelid = t.relid AND g.tgname = :trigger) AS state
                   FROM pg_partition_tree(to_regclass(:table)) AS t WHERE t.level > 0 ORDER BY t.relid::regclass::text COLLATE "C"',
                ['trigger' => $trigger, 'table' => $table],
            );
            $missing = [];
            $disabled = [];
            foreach ($rows as $row) {
                $part = Coerce::str($row['part']);
                if ($row['state'] === null) {
                    $missing[] = $part;
                } elseif ($row['state'] === 'D') {
                    $disabled[] = $part;
                }
            }
            if ($missing !== []) {
                $checks[] = Check::error($label, sprintf('%s missing on %s: a TRUNCATE of such a partition leaves stale documents in the index.', $trigger, implode(', ', $missing)), self::APPLY);
            }
            if ($disabled !== []) {
                $checks[] = Check::error($label, sprintf('%s exists but is DISABLED on %s', $trigger, implode(', ', $disabled)), implode(' ', array_map(
                    static fn(string $part): string => sprintf('ALTER TABLE %s ENABLE TRIGGER %s;', $part, Sql::ident($trigger)),
                    $disabled,
                )));
            }
            if ($rows !== [] && $checks === []) {
                $checks[] = Check::ok($label, sprintf('%d partition(s), each with the TRUNCATE trigger', count($rows)));
            }
            if ($rows !== [] && $index->triggerLevel === TriggerLevel::Statement) {
                $checks[] = Check::warning($label, 'Writes that target a partition directly are not synced (statement-level triggers cannot go on partitions); use trigger_level: row, or write through the parent.');
            }
        }
        $left = $this->connection->fetchValue(
            sprintf(
                'SELECT string_agg(g.tgrelid::regclass::text, \', \' ORDER BY g.tgrelid::regclass::text COLLATE "C") FROM pg_trigger AS g WHERE g.tgname = :trigger AND g.tgfoid = to_regprocedure(:function) AND g.tgrelid IS DISTINCT FROM to_regclass(:table)%s',
                $index->sync->usesTriggers() ? ' AND g.tgrelid NOT IN (SELECT t.relid FROM pg_partition_tree(to_regclass(:table)) AS t)' : '',
            ),
            ['trigger' => $trigger, 'function' => $this->names->syncFunction($index, $watch) . '()', 'table' => $table],
        );
        if ($left !== null) {
            $checks[] = Check::warning($label, sprintf('%s is left on %s: a TRUNCATE there still triggers a full resync of the index.', $trigger, Coerce::str($left)), self::APPLY);
        }

        return $checks;
    }

    /** @return list<Check> */
    private function triggers(IndexDefinition $index): array
    {
        $checks = [];
        foreach ($index->effectiveWatches() as $watch) {
            $rows = $this->connection->fetchAll(
                'SELECT tgname, tgenabled FROM pg_trigger WHERE tgrelid = to_regclass(:table) AND NOT tgisinternal',
                ['table' => $watch->table],
            );
            $state = array_column($rows, 'tgenabled', 'tgname');
            $label = 'Sync trigger on ' . $watch->table;
            $expected = array_keys($this->schema->triggerDefinitions($index, $watch));

            $missing = array_values(array_filter($expected, static fn(string $t): bool => !isset($state[$t])));
            $disabled = array_values(array_filter($expected, static fn(string $t): bool => ($state[$t] ?? null) === 'D'));
            $leftover = array_values(array_filter($this->schema->obsoleteTriggerNames($index, $watch), static fn(string $t): bool => isset($state[$t])));

            if ($missing !== []) {
                // An index set up before the TRUNCATE trigger existed only lacks that one.
                // (compared by name: Names::limit() hashes a long name, which then no longer ends in "_trn")
                $truncateTrigger = $this->names->triggerName($index, $watch, '_trn');
                $truncateOnly = array_all($missing, static fn(string $t): bool => $t === $truncateTrigger);
                $checks[] = Check::error($label, sprintf(
                    $truncateOnly ? 'missing %s: a TRUNCATE of this table leaves stale documents in the index' : 'missing %s: changes to this table are not indexed',
                    implode(', ', $missing),
                ), self::APPLY);
            } elseif ($disabled !== []) {
                $checks[] = Check::error($label, sprintf('%s exists but is DISABLED', implode(', ', $disabled)), implode(' ', array_map(
                    static fn(string $t): string => sprintf('ALTER TABLE %s ENABLE TRIGGER %s;', Sql::ident($watch->table), Sql::ident($t)),
                    $disabled,
                )));
            } elseif ($expected !== []) {
                $checks[] = Check::ok($label, sprintf('%d trigger(s), %s mode, %s level', count($expected), $index->sync->value, $index->triggerLevel->value));
            }
            if ($leftover !== []) {
                $checks[] = Check::warning($label, sprintf('Leftover trigger(s) %s do not match "%s" sync / %s level and cause double work.', implode(', ', $leftover), $index->sync->value, $index->triggerLevel->value), self::APPLY);
            }
            array_push($checks, ...$this->partitions($index, $watch));
        }
        if (!$index->sync->usesTriggers()) {
            $checks[] = Check::ok('Sync', sprintf('"%s" mode: no database triggers expected', $index->sync->value));
        }

        return $checks;
    }

    private function queue(IndexDefinition $index, InspectOptions $options): Check
    {
        if (!$this->regclass($this->names->queue())) {
            return Check::error('Sync queue', 'Queue table is missing.', self::APPLY);
        }
        $row = $this->connection->fetchAll(
            sprintf("SELECT count(*) AS n, coalesce(bool_or(doc_id = '*'), false) AS rebuild, coalesce(extract(epoch FROM now() - min(queued_at)), 0)::bigint AS age FROM %s WHERE index_name = :index", $this->names->queue()),
            ['index' => $index->name],
        )[0];
        $size = Coerce::int($row['n']);
        $age = Coerce::int($row['age']);
        $rebuild = $row['rebuild'] === true;
        $waiting = sprintf('%d item(s) waiting%s', $size, $rebuild ? ', one of them a full rebuild (queued by a TRUNCATE)' : '');
        $failure = $rebuild ? $this->rebuildFailure($index) : null;

        return match (true) {
            $failure !== null => Check::warning(
                'Sync queue',
                sprintf('%s; a full rebuild keeps failing: %s (%d times, last at %s)', $waiting, Coerce::str($failure['rebuild_error']), Coerce::int($failure['rebuild_failures']), Coerce::str($failure['failed_at'])),
                sprintf('fix the cause; the worker retries with a back-off, or run: bin/console fuzzphony:reindex %s', $index->name),
            ),
            $size > $options->maxQueueBacklog || ($size > 0 && $age > $options->maxQueueAgeSeconds) => Check::warning(
                'Sync queue',
                sprintf('%s, oldest %ds: is the worker running?', $waiting, $age),
                'bin/console fuzzphony:worker   (or from cron: bin/console fuzzphony:worker --once)',
            ),
            default => Check::ok('Sync queue', $waiting),
        };
    }

    /**
     * The last failure the worker recorded for the index's rebuild job; null without one, or when
     * the meta table (an older schema: no failure columns) cannot tell.
     *
     * @return array<string, mixed>|null
     */
    private function rebuildFailure(IndexDefinition $index): ?array
    {
        $readable = $this->connection->fetchValue(
            "SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass(:meta) AND attname = 'rebuild_failures' AND NOT attisdropped) THEN has_table_privilege(:table, 'SELECT') ELSE false END",
            ['meta' => $this->names->meta(), 'table' => $this->names->meta()],
        );

        return $readable === true ? ($this->connection->fetchAll(
            sprintf("SELECT rebuild_failures, rebuild_error, to_char(rebuild_failed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || ' UTC' AS failed_at FROM %s WHERE index_name = :index AND rebuild_failures IS NOT NULL", $this->names->meta()),
            ['index' => $index->name],
        )[0] ?? null) : null;
    }

    private function coverage(IndexDefinition $index, InspectOptions $options): Check
    {
        if ($options->deep) {
            $source = Coerce::int($this->connection->fetchValue(sprintf('SELECT count(*) FROM (%s) AS d', DocumentSql::raw($index))));
            $indexed = Coerce::int($this->connection->fetchValue(sprintf('SELECT count(*) FROM %s', $this->names->sidecar($index))));
            $how = 'exact';
        } else {
            if ($index->source->table === null) {
                return Check::skipped('Coverage', 'Estimates are not available for query sources; run with --deep for exact counts.');
            }
            $estimate = 'SELECT greatest(reltuples, 0)::bigint FROM pg_class WHERE oid = to_regclass(:t)';
            $source = Coerce::int($this->connection->fetchValue($estimate, ['t' => $index->source->table]));
            $indexed = Coerce::int($this->connection->fetchValue($estimate, ['t' => $this->names->sidecar($index)]));
            $how = 'estimated';
        }

        if ($source === 0) {
            return Check::ok('Coverage', sprintf('source is empty (%s)', $how));
        }
        $ratio = $indexed / $source;

        return $ratio < 0.95 || $ratio > 1.05
            ? Check::warning('Coverage', sprintf('%d of %d documents indexed (%.1f%%, %s).', $indexed, $source, $ratio * 100, $how), sprintf('bin/console fuzzphony:reindex %s', $index->name))
            : Check::ok('Coverage', sprintf('%d of %d documents indexed (%.1f%%, %s)', $indexed, $source, $ratio * 100, $how));
    }

    /** Indexed documents whose id the source no longer returns: exact only, so --deep only. */
    private function orphans(IndexDefinition $index, InspectOptions $options): Check
    {
        if (!$options->deep) {
            return Check::skipped('Orphaned documents', 'Run with --deep to count indexed documents that are no longer in the source.');
        }
        $orphans = Coerce::int($this->connection->fetchValue(sprintf(
            'SELECT count(*) FROM %s AS s WHERE NOT EXISTS (SELECT 1 FROM (%s) AS doc WHERE doc.fz_id = s.id)',
            $this->names->sidecar($index),
            DocumentSql::select($index),
        )));

        return $orphans > 0
            ? Check::warning('Orphaned documents', sprintf('%d indexed document(s) are no longer in the source and can still be found.', $orphans), sprintf('bin/console fuzzphony:reindex %s', $index->name))
            : Check::ok('Orphaned documents', 'none');
    }

    /** @return list<Check> */
    private function configuration(IndexDefinition $index): array
    {
        $checks = [];
        $t = $index->thresholds;
        if ($t->fuzzyMode !== FuzzyMode::Never && !$index->hasFuzzy()) {
            $checks[] = Check::warning('Typo tolerance', 'fuzzy_mode is enabled but no field is marked fuzzy, so it has no effect.', 'Mark the most important field fuzzy: #[SearchField("A", fuzzy: true)]');
        }
        if ($t->fuzzySimilarity !== null && $t->fuzzySimilarity < 0.2) {
            $checks[] = Check::warning('Typo tolerance', sprintf('fuzzy_similarity %.2f is very tolerant; expect noisy matches.', $t->fuzzySimilarity));
        }
        if ($t->candidateLimit > 5_000) {
            $checks[] = Check::warning('Candidate limit', sprintf('candidate_limit %d may make frequent words slow to rank.', $t->candidateLimit));
        }
        if ($checks === []) {
            $checks[] = Check::ok('Configuration', sprintf('fuzzy=%s, similarity=%s, min_score=%.2f, candidates=%d', $t->fuzzyMode->value, $t->fuzzySimilarity === null ? 'by word length' : sprintf('%.2f', $t->fuzzySimilarity), $t->minScore, $t->candidateLimit));
        }

        return $checks;
    }

    /** @return list<Check> */
    private function tenantScoping(IndexDefinition $index): array
    {
        return $index->tenant !== null
            ? [Check::ok('Tenant scoping', sprintf('enforced via filter "%s"', $index->tenant))]
            : [];
    }

    /** @return list<Check> */
    private function columnAwareFiltering(IndexDefinition $index): array
    {
        $checks = [];
        if (!$index->sync->usesTriggers()) {
            // orm/manual sync never installs triggers, so relevantColumns() has no effect;
            // an "active"/error line here would just be noise.
            return $checks;
        }
        foreach ($index->effectiveWatches() as $watch) {
            if ($watch->columns !== null) {
                $missing = array_values(array_diff($watch->columns, $this->tableColumns($watch->table)));
                if ($missing !== []) {
                    $checks[] = Check::error(
                        'Column-aware filtering',
                        sprintf('Watch on "%s" names unknown column(s): %s.', $watch->table, implode(', ', $missing)),
                    );

                    continue;
                }
            }
            $columns = $this->schema->relevantColumns($index, $watch);
            if ($columns !== null) {
                $checks[] = Check::ok('Column-aware filtering', sprintf('active for %s (%s)', $watch->table, implode(', ', $columns)));
            }
        }

        return $checks;
    }

    /** @return list<string> */
    private function tableColumns(string $table): array
    {
        return array_map(Coerce::str(...), array_column($this->connection->fetchAll(
            'SELECT attname FROM pg_attribute WHERE attrelid = to_regclass(:table) AND attnum > 0 AND NOT attisdropped',
            ['table' => $table],
        ), 'attname'));
    }

    /**
     * What a full reindex leaves while it builds next to the live index (it holds the rebuild
     * lock), or after it failed: the rebuild table, its change log and the trigger on the live
     * table that fills the log (so the log grows with every change). With both tables a run with
     * --from continues it; with less, only a full run (which drops the rest) helps, and the trigger
     * without its log fails every write to the index.
     *
     * @return list<Check>
     */
    private function rebuild(IndexDefinition $index): array
    {
        $shadow = $this->names->shadow($index);
        $changes = $this->names->changes($index);
        $trigger = sprintf('trigger %s on %s', Sql::ident($this->names->trackFunctionName($index)), $this->names->sidecar($index));
        $row = $this->connection->fetchAll(
            'SELECT to_regclass(:shadow) IS NOT NULL AS shadow, to_regclass(:changes) IS NOT NULL AS changes,
                    EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = to_regclass(:sidecar) AND tgname = :trigger) AS trigger',
            ['shadow' => $shadow, 'changes' => $changes, 'sidecar' => $this->names->sidecar($index), 'trigger' => $this->names->trackFunctionName($index)],
        )[0];
        $found = [$shadow => $row['shadow'] === true, $changes => $row['changes'] === true, $trigger => $row['trigger'] === true];
        $left = array_keys(array_filter($found, static fn(bool $exists): bool => $exists));
        if ($left === []) {
            return [];
        }
        // a transaction-level probe, released when this transaction ends; inside a caller's transaction
        // it is held until that commits, and in the session holding the rebuild lock it re-enters (free)
        $free = $this->connection->transactional(fn(Connection $c): mixed => $c->fetchValue(
            'SELECT pg_try_advisory_xact_lock(hashtext(:key))',
            ['key' => $this->names->rebuildLockKey($index)],
        )) === true;
        if (!$free) {
            return [Check::ok('Rebuild', 'a full reindex is building the index next to the live one')];
        }
        $fix = sprintf('bin/console fuzzphony:reindex %s', $index->name);
        if ($found[$shadow] && $found[$changes]) {
            return [Check::warning(
                'Rebuild',
                sprintf('A rebuild of "%s" did not finish: %s is left over, and every change to the index is logged for it. Resume it with --from (the last id it printed), or run a full reindex, which starts over.', $index->name, $shadow),
                $fix,
            )];
        }
        $broken = $found[$trigger] && !$found[$changes];
        $message = sprintf(
            'A rebuild of "%s" did not finish and cannot be resumed: %s %s left over%s. Run a full reindex, which starts over.',
            $index->name,
            implode(', ', $left),
            count($left) === 1 ? 'is' : 'are',
            match (true) {
                $broken => ', and every write to the index fails on its missing change log',
                $found[$trigger] => ', and every change to the index is logged for it',
                default => '',
            },
        );

        return [$broken ? Check::error('Rebuild', $message, $fix) : Check::warning('Rebuild', $message, $fix)];
    }

    private function regclass(string $name): bool
    {
        return (bool) $this->connection->fetchValue('SELECT to_regclass(:name) IS NOT NULL', ['name' => $name]);
    }
}
