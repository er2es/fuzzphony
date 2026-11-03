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
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the "--drop --apply" confirmation prompt (required in non-interactive runs)')
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

        $tablesBefore = $this->tables();
        $columnsBefore = $this->cachedColumns();
        $io->progressStart(count($plan->statements));
        $current = null;
        try {
            $warnings = $plan->apply($this->connection, static function (Statement $statement) use ($io, &$current): void {
                $current = $statement;
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
        $io->success(sprintf('%d statement(s) applied. Next: fuzzphony:reindex, then fuzzphony:doctor.', count($plan->statements)));
        if (array_any(array_diff($this->cachedColumns(), $columnsBefore), static fn(string $column): bool => isset($tablesBefore[explode('.', $column)[0]]))) {
            $io->warning('An index was added that the engine looks up once per process (the open-class indexes of an index with granted fields): restart long-running processes (Messenger workers, RoadRunner, FrankenPHP) so that they see it, or call PostgresEngine::resetCaches().');
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

    private function writeMigration(SchemaPlan $plan, string $directory, string $namespace): ?string
    {
        $class = 'Version' . date('YmdHis');
        $up = implode("\n", array_map(
            static fn(Statement $s): string => sprintf("        // %s\n        \$this->addSql(%s);", $s->description, var_export($s->sql, true)),
            // a statement that needs the database's answer to know what to run (dropping stale indexes) is for --apply, the doctor reports what is left
            array_filter($plan->statements, static fn(Statement $s): bool => $s->forEach === null),
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
