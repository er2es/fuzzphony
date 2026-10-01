<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Command;

use Fuzzphony\Bridge\Doctrine\SchemaAssetFilter;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Inspection\InspectOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal The fuzzphony:doctor console command; its CLI is public, the class is not. */
#[AsCommand(name: 'fuzzphony:doctor', description: 'Check that the database matches the index definitions, with fixes')]
final class DoctorCommand extends Command
{
    public function __construct(
        private readonly Fuzzphony $fuzzphony,
        /** The application's own DBAL schema_filter on Fuzzphony's connection; null = Fuzzphony's filter is in place. */
        private readonly ?string $applicationSchemaFilter = null,
        /** Fuzzphony's schema, whose tables that filter must hide. */
        private readonly string $schema = 'public',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('index', InputArgument::OPTIONAL, 'Only this index (default: all)')
            ->addOption('deep', null, InputOption::VALUE_NONE, 'Exact row counts instead of planner estimates (slower)')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit with a failure code on warnings too (useful in CI)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text (default) or prometheus', 'text')
            ->setHelp('Exit code 0 = healthy, 1 = errors (or warnings with --strict). Run it in CI after migrations.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $options = new InspectOptions(deep: $input->getOption('deep') === true);
        $worst = CheckStatus::Ok;
        $format = $input->getOption('format');
        $promLines = [];

        foreach (IndexArgument::resolve($this->fuzzphony, $input) as $index) {
            $report = $this->fuzzphony->engine()->inspect($index, $options);
            if ($format === 'prometheus') {
                foreach ($report->checks as $check) {
                    $promLines[] = sprintf('fuzzphony_doctor_check{index="%s",check="%s"} %d', $index->name, $check->name, $check->status === CheckStatus::Ok ? 1 : 0);
                }
                if ($index->sync === SyncMode::Queue) {
                    $promLines[] = sprintf('fuzzphony_queue_depth{index="%s"} %d', $index->name, $this->fuzzphony->engine()->queueSize($index));
                }
            } else {
                $io->section(sprintf('Index "%s"', $index->name));
                $rows = [];
                foreach ($report->checks as $check) {
                    $rows[] = [
                        match ($check->status) {
                            CheckStatus::Ok => '<info>✔</info>',
                            CheckStatus::Warning => '<comment>!</comment>',
                            CheckStatus::Error => '<error>✘</error>',
                            CheckStatus::Skipped => '-',
                        },
                        $check->name,
                        $check->message,
                    ];
                }
                $io->table(['', 'Check', 'Result'], $rows);
                foreach ($report->problems() as $problem) {
                    if ($problem->fix !== null) {
                        $io->writeln(sprintf(' <comment>Fix for "%s":</comment> %s', $problem->name, $problem->fix));
                    }
                }
            }
            $worst = match (true) {
                $report->status() === CheckStatus::Error => CheckStatus::Error,
                $report->status() === CheckStatus::Warning && $worst === CheckStatus::Ok => CheckStatus::Warning,
                default => $worst,
            };
        }

        if ($format !== 'prometheus' && $this->applicationSchemaFilter !== null && SchemaAssetFilter::letsThrough($this->applicationSchemaFilter, $this->schema)) {
            $io->section('Doctrine schema filter');
            $io->writeln(sprintf(
                ' <comment>!</comment> Your DBAL connection\'s schema_filter %s lets Fuzzphony\'s tables through, so "doctrine:migrations:diff" will propose dropping them. Merge Fuzzphony\'s filter into yours: %s',
                OutputFormatter::escape($this->applicationSchemaFilter),
                SchemaAssetFilter::regex($this->schema),
            ));
            $worst = $worst === CheckStatus::Ok ? CheckStatus::Warning : $worst;
        }

        if ($format === 'prometheus') {
            $output->writeln($promLines);

            return $worst === CheckStatus::Error || ($input->getOption('strict') === true && $worst === CheckStatus::Warning) ? Command::FAILURE : Command::SUCCESS;
        }

        $strict = $input->getOption('strict') === true;
        match ($worst) {
            CheckStatus::Error => $io->error('Problems found; see the fixes above.'),
            CheckStatus::Warning => $io->warning('Healthy, with warnings.'),
            default => $io->success('All checks passed.'),
        };

        return $worst === CheckStatus::Error || ($strict && $worst === CheckStatus::Warning) ? Command::FAILURE : Command::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('index')) {
            $suggestions->suggestValues(IndexArgument::names($this->fuzzphony));
        }
    }
}
