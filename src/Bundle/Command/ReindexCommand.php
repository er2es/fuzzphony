<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Command;

use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\ReindexOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal The fuzzphony:reindex console command; its CLI is public, the class is not. */
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
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Resume after this id (printed while running)')
            ->addOption('in-place', null, InputOption::VALUE_NONE, 'Write the live index directly: no second copy on disk, but searches see a mix of old and new documents while it runs')
            ->addOption('no-prune', null, InputOption::VALUE_NONE, 'Do not remove indexed documents this session cannot see in the source (row-level security, search_path)')
            ->addOption('prune-empty', null, InputOption::VALUE_NONE, 'Prune even when the source returns no row at all (wipes the whole index)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the "--prune-empty" confirmation prompt (required in non-interactive runs)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batch = max(1, Coerce::int($input->getOption('batch')));
        $from = $input->getOption('from');
        $noPrune = $input->getOption('no-prune') === true;
        $inPlace = $input->getOption('in-place') === true;
        $pruneEmpty = $input->getOption('prune-empty') === true;

        if ($pruneEmpty && !$this->confirmPruneEmpty($input, $io)) {
            return Command::FAILURE;
        }

        foreach (IndexArgument::resolve($this->fuzzphony, $input) as $index) {
            $io->section(sprintf('Reindexing "%s"', $index->name));
            $started = microtime(true);
            $lastId = null;
            try {
                $result = $this->fuzzphony->reindex($index->name, new ReindexOptions(
                    batchSize: $batch,
                    resumeAfter: is_string($from) ? $index->idType->cast($from) : null,
                    prune: !$noPrune,
                    pruneEmpty: $pruneEmpty,
                    onBatch: static function (int $done, int|string $last) use ($io, $started, &$lastId): void {
                        $lastId = $last;
                        $rate = $done / max(0.001, microtime(true) - $started);
                        $io->writeln(sprintf('  %s documents, %s/s, last id %s <comment>(resume: --from=%s)</comment>', number_format($done), number_format($rate), $last, $last));
                    },
                    inPlace: $inPlace,
                ));
            } catch (FuzzphonyException $e) {
                // a failed run keeps what it built (a full run: its rebuild), so resuming continues it
                $io->writeln(sprintf('  <error>%s</error>', OutputFormatter::escape($e->getMessage())));
                if ($lastId !== null) {
                    $io->writeln(sprintf('  Resume it: bin/console fuzzphony:reindex %s --from=%s', $index->name, $lastId));
                }

                return Command::FAILURE;
            }
            $io->writeln(sprintf('  <info>%s documents in %.1fs</info>', number_format($result->written), microtime(true) - $started));
            $io->writeln(match (true) {
                $result->swapped => '  Built next to the live index and swapped in: searches never saw a partial index, and documents the source no longer returns went with the old one.',
                $result->pruned !== null => sprintf('  %s orphaned document(s) removed (no longer in the source)', number_format($result->pruned)),
                $result->pruneSkippedEmptySource => '  <comment>The source returned no rows for this session, so nothing was pruned (row-level security or search_path? a TRUNCATE is handled by its trigger). Use --prune-empty to remove every indexed document anyway.</comment>',
                $noPrune => '  <comment>Pruning skipped (--no-prune).</comment>',
                default => '  <comment>Orphaned documents are only removed by a full run (without --from).</comment>',
            });
            if (!$result->swapped && !$inPlace && !$noPrune && !is_string($from) && !$result->pruneSkippedEmptySource) {
                $io->writeln('  <comment>Rebuilt in place: this role cannot build the index next to the live one (it needs CREATE on Fuzzphony\'s schema and ownership of the index table), or fuzzphony:schema --apply has not run since the upgrade.</comment>');
            }
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

    private function confirmPruneEmpty(InputInterface $input, SymfonyStyle $io): bool
    {
        if ($input->getOption('force') === true) {
            return true;
        }

        $io->writeln('This will remove every indexed document of any index whose source returns no row this run (row-level security, search_path, or the source is genuinely empty); an index with at least one source row is pruned as usual.');

        if (!$input->isInteractive()) {
            $io->writeln('<error>Refusing to prune an empty source without confirmation: pass --force in non-interactive runs.</error>');

            return false;
        }

        return (bool) $io->askQuestion(new ConfirmationQuestion('Prune every document of an empty source? (yes/no) [no]:', false));
    }
}
