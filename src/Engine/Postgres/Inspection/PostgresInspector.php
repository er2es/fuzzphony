<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Inspection;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\InspectionReport;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Ranking\FuzzyMode;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Support\Identifier;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Sql\DocumentSql;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * "fuzzphony:doctor": verifies that the database matches the definition and says how to fix
 * what does not. Read-only (the probe view is temporary and dropped immediately).
 */
final class PostgresInspector
{
    private const string APPLY = 'bin/console fuzzphony:schema --apply';

    public function __construct(
        private readonly Connection $connection,
        private readonly PostgresSchemaGenerator $schema,
    ) {}

    public function inspect(IndexDefinition $index, InspectOptions $options): InspectionReport
    {
        $checks = [];
        $checks[] = $this->version();
        array_push($checks, ...$this->extensions($index));
        $checks[] = $this->textConfig($index);
        $checks[] = $this->function(PostgresSchemaGenerator::NORM_FUNCTION . '(text)', 'Normaliser function');

        $sourceColumns = $this->sourceColumns($index, $checks);
        if ($sourceColumns !== null) {
            array_push($checks, ...$this->sourceMapping($index, $sourceColumns));
        }
        if ($index->source->table !== null) {
            $checks[] = $this->sourceKey($index);
        }

        $sidecarExists = $this->regclass($index->sidecarTable());
        if (!$sidecarExists) {
            $checks[] = Check::error('Sidecar table', sprintf('Table "%s" does not exist.', $index->sidecarTable()), self::APPLY);
        } else {
            array_push($checks, ...$this->sidecarColumns($index));
            array_push($checks, ...$this->sidecarIndexes($index));
        }
        $checks[] = $this->function(
            sprintf('%s(%s[])', $this->schema->refreshFunctionName($index), $index->idType->sqlType()),
            'Refresh function',
        );
        array_push($checks, ...$this->triggers($index));
        $checks[] = $this->queue($index, $options);
        if ($sidecarExists && $sourceColumns !== null) {
            $checks[] = $this->coverage($index, $options);
            $checks[] = $this->orphans($index, $options);
        }
        array_push($checks, ...$this->configuration($index));
        array_push($checks, ...$this->tenantScoping($index));
        array_push($checks, ...$this->columnAwareFiltering($index));

        return new InspectionReport($index->name, $checks);
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
        $name = $index->text->configName();
        $exists = (bool) $this->connection->fetchValue('SELECT count(*) > 0 FROM pg_ts_config WHERE cfgname = :name', ['name' => $name]);

        return $exists
            ? Check::ok('Text search configuration', $name)
            : Check::error('Text search configuration', sprintf('"%s" is missing.', $name), self::APPLY);
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
                !in_array($type, $filter->type->compatibleSqlTypes(), true) => Check::warning(
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
                !in_array($actual, $type->compatibleSqlTypes(), true) => Check::error($label, sprintf('Column "%s" is %s; expected a %s type.', $column, $actual, $type === FilterType::Float ? 'numeric' : 'date/timestamp')),
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
            ['table' => $index->sidecarTable()],
        ), 'attname'));
        $expected = array_keys($this->schema->columns($index));
        $missing = array_diff($expected, $actual);
        $extra = array_diff($actual, $expected);

        $checks = [];
        $checks[] = $missing === []
            ? Check::ok('Sidecar columns', sprintf('%d column(s) match the definition', count($expected)))
            : Check::error('Sidecar columns', sprintf('Schema drift, missing: %s.', implode(', ', $missing)), self::APPLY);
        if ($extra !== []) {
            $checks[] = Check::warning(
                'Sidecar columns',
                sprintf('Columns no longer in the definition: %s (harmless, but they waste space).', implode(', ', $extra)),
                implode(' ', array_map(static fn(string $c): string => sprintf('ALTER TABLE %s DROP COLUMN %s;', Sql::ident($index->sidecarTable()), Sql::ident($c)), $extra)),
            );
        }

        return $checks;
    }

    /** @return list<Check> */
    private function sidecarIndexes(IndexDefinition $index): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT c.relname, i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE i.indrelid = to_regclass(:table)',
            ['table' => $index->sidecarTable()],
        );
        $valid = [];
        foreach ($rows as $row) {
            $valid[Coerce::str($row['relname'])] = (bool) $row['indisvalid'];
        }

        $checks = [];
        foreach ($this->schema->indexes($index) as $name => $definition) {
            $create = sprintf('CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s %s;', Sql::ident($name), Sql::ident($index->sidecarTable()), $definition);
            $checks[] = match (true) {
                !array_key_exists($name, $valid) => Check::error('Index ' . $name, 'missing', $create),
                !$valid[$name] => Check::error('Index ' . $name, 'INVALID (an interrupted concurrent build); it is ignored by the planner', sprintf('DROP INDEX CONCURRENTLY %s; %s', Sql::ident($name), $create)),
                default => Check::ok('Index ' . $name, 'valid'),
            };
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
                // (compared by name: Identifier::limit() hashes a long name, which then no longer ends in "_trn")
                $truncateTrigger = Identifier::limit($this->schema->syncFunctionName($index, $watch) . '_trn');
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
        }
        if (!$index->sync->usesTriggers()) {
            $checks[] = Check::ok('Sync', sprintf('"%s" mode: no database triggers expected', $index->sync->value));
        }

        return $checks;
    }

    private function queue(IndexDefinition $index, InspectOptions $options): Check
    {
        if (!$this->regclass(PostgresSchemaGenerator::QUEUE_TABLE)) {
            return Check::error('Sync queue', 'Queue table is missing.', self::APPLY);
        }
        $row = $this->connection->fetchAll(
            sprintf('SELECT count(*) AS n, coalesce(extract(epoch FROM now() - min(queued_at)), 0)::bigint AS age FROM %s WHERE index_name = :index', PostgresSchemaGenerator::QUEUE_TABLE),
            ['index' => $index->name],
        )[0];
        $size = Coerce::int($row['n']);
        $age = Coerce::int($row['age']);

        return match (true) {
            $size > $options->maxQueueBacklog || ($size > 0 && $age > $options->maxQueueAgeSeconds) => Check::warning(
                'Sync queue',
                sprintf('%d item(s) waiting, oldest %ds: is the worker running?', $size, $age),
                'bin/console fuzzphony:worker   (or from cron: bin/console fuzzphony:worker --once)',
            ),
            default => Check::ok('Sync queue', sprintf('%d item(s) waiting', $size)),
        };
    }

    private function coverage(IndexDefinition $index, InspectOptions $options): Check
    {
        if ($options->deep) {
            $source = Coerce::int($this->connection->fetchValue(sprintf('SELECT count(*) FROM (%s) AS d', DocumentSql::raw($index))));
            $indexed = Coerce::int($this->connection->fetchValue(sprintf('SELECT count(*) FROM %s', Sql::ident($index->sidecarTable()))));
            $how = 'exact';
        } else {
            if ($index->source->table === null) {
                return Check::skipped('Coverage', 'Estimates are not available for query sources; run with --deep for exact counts.');
            }
            $estimate = 'SELECT greatest(reltuples, 0)::bigint FROM pg_class WHERE oid = to_regclass(:t)';
            $source = Coerce::int($this->connection->fetchValue($estimate, ['t' => $index->source->table]));
            $indexed = Coerce::int($this->connection->fetchValue($estimate, ['t' => $index->sidecarTable()]));
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
            Sql::ident($index->sidecarTable()),
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
        if ($t->fuzzySimilarity < 0.2) {
            $checks[] = Check::warning('Typo tolerance', sprintf('fuzzy_similarity %.2f is very tolerant; expect noisy matches.', $t->fuzzySimilarity));
        }
        if ($t->candidateLimit > 20_000) {
            $checks[] = Check::warning('Candidate limit', sprintf('candidate_limit %d may make frequent words slow to rank.', $t->candidateLimit));
        }
        if ($checks === []) {
            $checks[] = Check::ok('Configuration', sprintf('fuzzy=%s, similarity=%.2f, min_score=%.2f, candidates=%d', $t->fuzzyMode->value, $t->fuzzySimilarity, $t->minScore, $t->candidateLimit));
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

    private function regclass(string $name): bool
    {
        return (bool) $this->connection->fetchValue('SELECT to_regclass(:name) IS NOT NULL', ['name' => $name]);
    }
}
