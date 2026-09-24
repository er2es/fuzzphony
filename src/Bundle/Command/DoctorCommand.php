<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Command;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Inspection\InspectOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fuzzphony:doctor', description: 'Check that the database matches the index definitions, with fixes')]
final class DoctorCommand extends Command
{
    public function __construct(private readonly Fuzzphony $fuzzphony)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('index', InputArgument::OPTIONAL, 'Only this index (default: all)')
            ->addOption('deep', null, InputOption::VALUE_NONE, 'Exact row counts instead of planner estimates (slower)')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit with a failure code on warnings too (useful in CI)')
            ->setHelp('Exit code 0 = healthy, 1 = errors (or warnings with --strict). Run it in CI after migrations.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $options = new InspectOptions(deep: $input->getOption('deep') === true);
        $worst = CheckStatus::Ok;

        foreach (IndexArgument::resolve($this->fuzzphony, $input) as $index) {
            $report = $this->fuzzphony->engine()->inspect($index, $options);
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
            $worst = match (true) {
                $report->status() === CheckStatus::Error => CheckStatus::Error,
                $report->status() === CheckStatus::Warning && $worst === CheckStatus::Ok => CheckStatus::Warning,
                default => $worst,
            };
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
