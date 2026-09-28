<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Command;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Core\Support\Coerce;
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
    public function __construct(
        private readonly Fuzzphony $fuzzphony,
        private readonly Connection $connection,
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
            ->setHelp(<<<'HELP'
                Without options the SQL is printed, so you can review it first:

                  <info>bin/console fuzzphony:schema</info>
                  <info>bin/console fuzzphony:schema products --apply</info>
                  <info>bin/console fuzzphony:schema --dump-migration=migrations</info>

                Indexes are built with CREATE INDEX CONCURRENTLY, so production tables stay writable.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $indexes = IndexArgument::resolve($this->fuzzphony, $input);
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

        $io->progressStart(count($plan->statements));
        $plan->apply($this->connection, static function (Statement $statement) use ($io): void {
            $io->progressAdvance();
        });
        $io->progressFinish();
        $io->success(sprintf('%d statement(s) applied. Next: fuzzphony:reindex, then fuzzphony:doctor.', count($plan->statements)));

        return Command::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('index')) {
            $suggestions->suggestValues(IndexArgument::names($this->fuzzphony));
        }
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
            $plan->statements,
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
