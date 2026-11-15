<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Command;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Support\Identifier;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\ColumnMap;
use Fuzzphony\Engine\Postgres\Schema\GrantRole;
use Fuzzphony\Engine\Postgres\Schema\Grants;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\OpenClass;
use Fuzzphony\Engine\Postgres\Sql\Sql;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal The fuzzphony:schema console command; its CLI is public, the class is not. */
#[AsCommand(name: 'fuzzphony:schema', description: 'Show, apply or export the SQL that creates the search indexes', aliases: ['fuzzphony:install'])]
final class SchemaCommand extends Command
{
    private const array PURPOSE = [
        'search' => 'the application: searches, writes the watched tables (their trigger appends to the shared log) and the search log; no DDL',
        'worker' => 'fuzzphony:worker: drains the log into the queue and refreshes the sidecar tables; no DDL',
        'admin' => 'fuzzphony:schema, fuzzphony:reindex, fuzzphony:doctor and the search log commands: DDL, triggers, ownership',
    ];

    public function __construct(
        private readonly Fuzzphony $fuzzphony,
        private readonly Connection $connection,
        private readonly Grants $grants = new Grants(new Names(), false),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('index', InputArgument::OPTIONAL, 'Only this index (default: all)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Execute the SQL (idempotent, safe to re-run)')
            ->addOption('drop', null, InputOption::VALUE_NONE, 'Remove the index objects instead (source tables are never touched)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation prompts of "--drop --apply" and of the column backfill (required in non-interactive runs)')
            ->addOption('dump-migration', null, InputOption::VALUE_REQUIRED, 'Write a Doctrine migration into this directory instead of applying')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace of the generated migration', 'DoctrineMigrations')
            ->addOption('dump-grants', null, InputOption::VALUE_NONE, 'Print the GRANT statements of the least-privilege database roles (docs/roles.md) instead of the schema')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'With --dump-grants: only this role: search, worker or admin (default: all three)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'With --dump-grants and --role: the name of your PostgreSQL role (default: fuzzphony_<role>)')
            ->setHelp(<<<'HELP'
                Without options the SQL is printed, so you can review it first:

                  <info>bin/console fuzzphony:schema</info>
                  <info>bin/console fuzzphony:schema products --apply</info>
                  <info>bin/console fuzzphony:schema --dump-migration=migrations</info>
                  <info>bin/console fuzzphony:schema --dump-grants --role=worker --to=my_worker</info>

                Indexes are built with CREATE INDEX CONCURRENTLY, so production tables stay writable.
                When rows were written with another column map (a definition change), --apply asks before it copies their
                emptied per-field columns back (in batches); pass --force to run it in a non-interactive run.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $indexes = IndexArgument::resolve($this->fuzzphony, $input);
        if ($input->getOption('dump-grants') === true) {
            return $this->dumpGrants($input, $output, $io, $indexes);
        }
        $engine = $this->fuzzphony->engine();

        $plan = new SchemaPlan();
        if ($input->getOption('drop') === true) {
            foreach ($indexes as $index) {
                $plan = $plan->merge($engine->dropSchema($index));
            }
        } else {
            $plan = $engine->globalSchema(...$indexes);
            foreach ($indexes as $index) {
                $plan = $plan->merge($engine->indexSchema($index));
            }
        }

        $directory = $input->getOption('dump-migration');
        if (is_string($directory)) {
            $file = $this->writeMigration($plan, $directory, Coerce::str($input->getOption('namespace')));
            if ($file === null) {
                $io->error(sprintf('Cannot create directory "%s".', $directory));

                return Command::FAILURE;
            }
            $io->success(sprintf('Migration written to %s (non-transactional, because indexes are built concurrently).', $file));

            return Command::SUCCESS;
        }

        if ($input->getOption('apply') !== true) {
            $output->writeln($plan->toSql());
            $io->note('Nothing was executed. Re-run with --apply, or export with --dump-migration=migrations.');

            return Command::SUCCESS;
        }

        if ($input->getOption('drop') === true && !$this->confirmDrop($input, $io, $indexes)) {
            return Command::FAILURE;
        }

        $skipped = 0;
        if ($input->getOption('drop') !== true) {
            [$plan, $skipped] = $this->confirmBackfill($input, $io, $indexes, $plan);
        }

        $tablesBefore = $this->tables();
        $columnsBefore = $this->cachedColumns();
        $io->progressStart(count($plan->statements));
        $current = null;
        $growth = [];
        $sidecars = $this->sidecars($indexes);
        try {
            $warnings = $plan->apply($this->connection, function (Statement $statement) use ($io, &$current, &$growth, $sidecars): void {
                $current = $statement;
                $this->measureBackfill($statement, $sidecars, $growth);
                $io->progressAdvance();
            });
        } catch (EngineFailure $e) {
            $table = $current !== null && (stripos($current->sql, 'TRIGGER') !== false || stripos($current->sql, 'watch_install') !== false) ? self::deniedTable($e) : null;
            if ($table === null) {
                throw $e;
            }
            $io->newLine();
            $io->error(sprintf(
                "This database role cannot create the triggers on the table %s: it needs to be the owner of the table (or hold its TRIGGER privilege) for sync: queue or trigger. Nothing was applied (the apply is one transaction).
"
                . 'Either run fuzzphony:schema --apply as the table owner (or grant the privilege), or use sync: manual, which needs no triggers: call refresh() after a change, or fuzzphony:reindex (see docs/sync.md, "A database where you cannot create triggers").',
                $table,
            ));

            return Command::FAILURE;
        }
        $io->progressFinish();
        foreach ($warnings as $warning) {
            $io->warning($warning);
        }
        foreach ($growth as $name => [$before, $after, $vacuums]) {
            if ($after !== null) {
                // the VACUUM ran when the table's manual vacuum count went up and no warning says it failed (a pooler that gave the
                // forEach query another session skips it; a role that does not own the table only gets a WARNING from PostgreSQL)
                $vacuumed = !array_any($warnings, static fn(string $w): bool => str_starts_with($w, sprintf('%s ("%s")', ColumnMap::VACUUM, $name)))
                    && $this->vacuumCount($sidecars[$name]) > $vacuums;
                $io->note(sprintf(
                    $vacuumed
                        ? '%s: the backfill grew the table from %s to %s (the copied columns and the old row versions; VACUUM (ANALYZE) made the old versions reusable). Run fuzzphony:reindex %s to compact it online.'
                        : '%s: the backfill grew the table from %s to %s (the copied columns and the old row versions). The VACUUM did not run, so the table stays this large: run "VACUUM (ANALYZE) %s", or fuzzphony:reindex %s, which also compacts it online.',
                    $name,
                    self::megabytes($before),
                    self::megabytes($after),
                    ...($vacuumed ? [$name] : [$sidecars[$name], $name]),
                ));
            }
        }
        $io->success(sprintf('%d statement(s) applied. Next: fuzzphony:reindex, then fuzzphony:doctor.', count($plan->statements)));
        $columnsAfter = $this->cachedColumns();
        $tablesAfter = $this->tables();
        if (array_any(array_diff($columnsAfter, $columnsBefore), static fn(string $column): bool => isset($tablesBefore[explode('.', $column)[0]]))) {
            $io->warning('An index was added that the engine looks up once per process (the open-class indexes of an index with granted fields): restart long-running processes (Messenger workers, RoadRunner, FrankenPHP) so that they see it, or call PostgresEngine::resetCaches().');
        } elseif (array_any(array_diff($columnsBefore, $columnsAfter), static fn(string $column): bool => isset($tablesAfter[explode('.', $column)[0]]))) {
            // a process that saw the dropped index still sends its lookups to the expression it served: the same results, without the index
            $io->warning('An index was dropped that the engine looks up once per process (an open-class index that is no longer needed): restart long-running processes (Messenger workers, RoadRunner, FrankenPHP) so that their searches stop reading the expression it served (the same results, but without the index), or call PostgresEngine::resetCaches().');
        }
        if ($skipped > 0) {
            $io->warning(sprintf(
                'The column backfill was skipped: %d document(s) written with another column map are read from their own per-field columns, and the ones that map left empty miss searches on those fields (for example a viewer restricted to a field). Run fuzzphony:schema --apply --force (or answer yes), or fuzzphony:reindex.',
                $skipped,
            ));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** @param list<IndexDefinition> $indexes */
    private function dumpGrants(InputInterface $input, OutputInterface $output, SymfonyStyle $io, array $indexes): int
    {
        $only = $input->getOption('role');
        $to = $input->getOption('to');
        $role = is_string($only) ? GrantRole::tryFrom($only) : null;
        if (is_string($only) && $role === null) {
            $io->error(sprintf('Unknown role "%s": use search, worker or admin.', $only));

            return Command::INVALID;
        }
        if (is_string($to) && ($role === null || !Identifier::isColumn($to))) {
            $io->error('--to names one PostgreSQL role (a plain identifier) and needs --role.');

            return Command::INVALID;
        }
        $database = Coerce::str($this->connection->fetchValue('SELECT current_database()'));
        foreach ($role !== null ? [$role] : GrantRole::cases() as $each) {
            $name = is_string($to) ? $to : 'fuzzphony_' . $each->value;
            $output->writeln(sprintf('-- %s: %s', $each->value, self::PURPOSE[$each->value]));
            $output->writeln(sprintf("-- CREATE ROLE %s LOGIN PASSWORD '...' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;", Sql::ident($name)));
            foreach ($this->grants->for($each, $indexes, $database) as $grant) {
                $line = $grant->sql($name);
                $output->writeln($this->connection->fetchValue($grant->exists()) === true
                    ? sprintf("-- %s%s\n%s", $grant->why, $grant->optional ? ' (a table of your application: skip it if it lives elsewhere)' : '', $line)
                    : sprintf('-- not there yet (run fuzzphony:schema --apply, then this again): %s', $line));
            }
            if ($each === GrantRole::Admin) {
                $output->writeln('-- A GRANT cannot give ownership. Run fuzzphony:schema --apply as this role (it then owns what it creates), or hand over what exists:');
                foreach ($this->grants->owned($indexes) as $table) {
                    $output->writeln(sprintf('-- ALTER TABLE %s OWNER TO %s;', $table, Sql::ident($name)));
                }
                $output->writeln('-- CREATE EXTENSION needs a superuser (pg_trgm and unaccent are trusted: the owner of the database may create them from PostgreSQL 13).');
            } else {
                $output->writeln('-- A table created later (another index, the search log) needs these again: run this after every fuzzphony:schema --apply.');
            }
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('index')) {
            $suggestions->suggestValues(IndexArgument::names($this->fuzzphony));
        }
    }

    /** The table a "permission denied" / "must be owner" failure names, or null for any other failure. */
    private static function deniedTable(\Throwable $failure): ?string
    {
        for ($e = $failure; $e !== null; $e = $e->getPrevious()) {
            if (preg_match('/(?:permission denied for table|must be owner of table) (\S+)/', $e->getMessage(), $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * @param list<IndexDefinition> $indexes
     *
     * @return array<string, string> index name => its sidecar (none for another engine)
     */
    private function sidecars(array $indexes): array
    {
        $engine = $this->fuzzphony->engine();
        if (!$engine instanceof PostgresEngine) {
            return [];
        }
        $sidecars = [];
        foreach ($indexes as $index) {
            $sidecars[$index->name] = $engine->schemaGenerator()->names()->sidecar($index);
        }

        return $sidecars;
    }

    /**
     * The size of a sidecar (pg_total_relation_size: the table, its TOAST and its indexes) when its backfill starts, and when the
     * VACUUM after it starts, the latter only when the backfill updated rows (ColumnMap::BACKFILLED names the table then).
     *
     * @param array<string, string>                  $sidecars index name => sidecar
     * @param array<string, array{int, int|null, int}> $growth   index name => [size before, size after or null, manual vacuums before the VACUUM]
     */
    private function measureBackfill(Statement $statement, array $sidecars, array &$growth): void
    {
        $backfill = str_starts_with($statement->description, ColumnMap::BACKFILL);
        if (!$backfill && !str_starts_with($statement->description, ColumnMap::VACUUM)) {
            return;
        }
        foreach ($sidecars as $name => $table) {
            if (!str_contains($statement->description, sprintf('("%s"', $name))) {
                continue;
            }
            $size = fn(): int => Coerce::int($this->connection->fetchValue('SELECT coalesce(pg_total_relation_size(to_regclass(:t)), 0)', ['t' => $table]));
            if ($backfill) {
                $growth[$name] = [$size(), null, 0];
            } elseif (isset($growth[$name]) && $this->connection->fetchValue(sprintf("SELECT current_setting('%s', true)", ColumnMap::BACKFILLED)) === $table) {
                $growth[$name][1] = $size();
                $growth[$name][2] = $this->vacuumCount($table);
            }

            return;
        }
    }

    /** How many times the table was vacuumed by hand (pg_stat_user_tables; autovacuum counts apart), read afresh. */
    private function vacuumCount(string $table): int
    {
        $this->connection->fetchValue('SELECT pg_stat_clear_snapshot()');

        return Coerce::int($this->connection->fetchValue('SELECT coalesce((SELECT vacuum_count FROM pg_stat_user_tables WHERE relid = to_regclass(:t)), 0)', ['t' => $table]));
    }

    private static function megabytes(int $bytes): string
    {
        return sprintf('%.1f MB', $bytes / 1_048_576);
    }

    /** @return array<string, true> the oids of the tables there are now */
    private function tables(): array
    {
        return array_fill_keys(array_map(Coerce::str(...), array_column($this->connection->fetchAll("SELECT oid::text AS oid FROM pg_class WHERE relkind IN ('r', 'p')"), 'oid')), true);
    }

    /**
     * The indexes the engine looks up once per process (the open-class indexes, see OpenClass), as
     * "table oid.name": one that appears in a table that was there before the apply is the one a long-running process does not know
     * about (a fresh install has no such process).
     *
     * @return list<string>
     */
    private function cachedColumns(): array
    {
        return array_map(Coerce::str(...), array_column($this->connection->fetchAll("SELECT i.indrelid::text || '.' || c.relname AS col FROM pg_index AS i JOIN pg_class AS c ON c.oid = i.indexrelid WHERE c.relname ~ '_" . OpenClass::NAME_TAIL . "$'"), 'col'));
    }

    /** @param list<IndexDefinition> $indexes */
    private function confirmDrop(InputInterface $input, SymfonyStyle $io, array $indexes): bool
    {
        if ($input->getOption('force') === true) {
            return true;
        }

        $io->writeln(sprintf(
            'This will drop the Fuzzphony objects for %s: the sidecar table, its triggers, functions and queued rows; your source tables are not touched.',
            implode(', ', array_map(static fn(IndexDefinition $index): string => $index->name, $indexes)),
        ));

        if (!$input->isInteractive()) {
            $io->writeln('<error>Refusing to drop without confirmation: pass --force in non-interactive runs.</error>');

            return false;
        }

        return (bool) $io->askQuestion(new ConfirmationQuestion('Drop these Fuzzphony objects? (yes/no) [no]:', false));
    }

    /**
     * The backfill (ColumnMap) rewrites every row written with another column map: it is asked for (default no; --force runs it, a
     * non-interactive run without it skips it). Without such rows nothing is asked and the statement runs (a scan that finds nothing).
     *
     * @param list<IndexDefinition> $indexes
     *
     * @return array{SchemaPlan, int} the plan to apply, and how many rows wait because the backfill was left out
     */
    private function confirmBackfill(InputInterface $input, SymfonyStyle $io, array $indexes, SchemaPlan $plan): array
    {
        $engine = $this->fuzzphony->engine();
        $rows = 0;
        $updates = 0;
        $lines = [];
        if ($engine instanceof PostgresEngine) {
            foreach ($indexes as $index) {
                foreach (ColumnMap::pending($this->connection, $engine->schemaGenerator()->names(), $index) as $map => $count) {
                    $rows += $count;
                    $updates += intdiv($count, ColumnMap::BATCH) + 1; // the last batch is the one with fewer rows
                    $lines[] = sprintf('%s: %d document(s) written with the map "%s"', $index->name, $count, $map);
                }
            }
        }
        $without = new SchemaPlan(array_values(array_filter($plan->statements, static fn(Statement $s): bool => !str_starts_with($s->description, ColumnMap::BACKFILL))));
        if ($rows === 0) {
            // nothing to ask about; the statement stays: a row of the old map may still arrive (a write in flight under the
            // old refresh function), and the backfill then finds it with one scan
            return [$plan, 0];
        }
        $io->writeln(sprintf(
            "Documents written with another column map (a definition change): their per-field columns that map left empty are copied back from the columns that hold the values.\n  %s\nThat is %d row(s) in %d UPDATE statement(s) of at most %d rows, each committed (about the time and WAL of updating those rows; searches go on meanwhile).",
            implode("\n  ", $lines),
            $rows,
            $updates,
            ColumnMap::BATCH,
        ));
        if ($input->getOption('force') === true) {
            return [$plan, 0];
        }
        if (!$input->isInteractive()) {
            $io->writeln('<comment>Not run without confirmation: pass --force in non-interactive runs.</comment>');

            return [$without, $rows];
        }

        return (bool) $io->askQuestion(new ConfirmationQuestion('Copy the columns back now? (yes/no) [no]:', false)) ? [$plan, 0] : [$without, $rows];
    }

    private function writeMigration(SchemaPlan $plan, string $directory, string $namespace): ?string
    {
        $class = 'Version' . date('YmdHis');
        $up = implode("\n", array_map(
            static fn(Statement $s): string => $s->forEach === null
                ? sprintf("        // %s\n        \$this->addSql(%s);", $s->description, var_export($s->sql, true))
                : sprintf('        // %s: not run here; when the backfill above updated rows, run "%s" outside a transaction afterwards (schema --apply does), and fuzzphony:reindex compacts the table online', $s->description, $s->sql),
            // a statement that needs the database's answer to know what to run (dropping stale indexes) is for --apply, the doctor
            // reports what is left; the VACUUM after a backfill is named as a hint
            array_filter($plan->statements, static fn(Statement $s): bool => $s->forEach === null || str_starts_with($s->description, ColumnMap::VACUUM)),
        ));
        $code = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Doctrine\\DBAL\\Schema\\Schema;
            use Doctrine\\Migrations\\AbstractMigration;

            /** Generated by "fuzzphony:schema --dump-migration". */
            final class {$class} extends AbstractMigration
            {
                public function getDescription(): string
                {
                    return 'Fuzzphony search indexes';
                }

                /** CREATE INDEX CONCURRENTLY cannot run inside a transaction. */
                public function isTransactional(): bool
                {
                    return false;
                }

                public function up(Schema \$schema): void
                {
            {$up}
                }

                public function down(Schema \$schema): void
                {
                    \$this->throwIrreversibleMigrationException('Use "bin/console fuzzphony:schema --drop --apply" to remove the indexes.');
                }
            }

            PHP;

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return null;
        }
        $file = rtrim($directory, '/') . '/' . $class . '.php';
        file_put_contents($file, $code);

        return $file;
    }
}
