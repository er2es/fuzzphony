<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Command;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Sync\Reindexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fuzzphony:reindex', description: 'Rebuild index documents from the source in resumable batches')]
final class ReindexCommand extends Command
{
    public function __construct(private readonly Fuzzphony $fuzzphony)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('index', InputArgument::OPTIONAL, 'Only this index (default: all)')
            ->addOption('batch', 'b', InputOption::VALUE_REQUIRED, 'Documents per batch', '5000')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Resume after this id (printed while running)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batch = max(1, (int) $input->getOption('batch'));
        $from = $input->getOption('from');
        $reindexer = new Reindexer($this->fuzzphony->engine());

        foreach (IndexArgument::resolve($this->fuzzphony, $input) as $index) {
            $io->section(sprintf('Reindexing "%s"', $index->name));
            $started = microtime(true);
            $resume = is_string($from) ? $index->idType->cast($from) : null;
            $total = $reindexer->run($index, $batch, $resume, static function (int $done, int|string $lastId) use ($io, $started): void {
                $rate = $done / max(0.001, microtime(true) - $started);
                $io->writeln(sprintf('  %s documents, %s/s, last id %s <comment>(resume: --from=%s)</comment>', number_format($done), number_format($rate), $lastId, $lastId));
            });
            $io->writeln(sprintf('  <info>%s documents in %.1fs</info>', number_format($total), microtime(true) - $started));
        }
        $io->success('Done. Tip: run fuzzphony:doctor to verify coverage.');

        return Command::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('index')) {
            $suggestions->suggestValues(IndexArgument::names($this->fuzzphony));
        }
    }
}
