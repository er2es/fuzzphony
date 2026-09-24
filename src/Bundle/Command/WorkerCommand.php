<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Command;

use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Sync\Worker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fuzzphony:worker', description: 'Process the sync queue (long-running, or --once from cron)')]
final class WorkerCommand extends Command implements SignalableCommandInterface
{
    private ?Worker $worker = null;

    public function __construct(
        private readonly Fuzzphony $fuzzphony,
        private readonly int $batchSize = 500,
        private readonly float $idleSleep = 1.0,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('index', 'i', InputOption::VALUE_REQUIRED, 'Only this index (default: all queue-mode indexes)')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Drain the queue once and exit (cron-friendly)')
            ->addOption('time-limit', 't', InputOption::VALUE_REQUIRED, 'Stop after this many seconds (let supervisor restart it)')
            ->addOption('batch', 'b', InputOption::VALUE_REQUIRED, 'Queue items per batch');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $indexes = array_values(array_filter(
            IndexArgument::resolve($this->fuzzphony, $input),
            static fn ($index): bool => $index->sync === SyncMode::Queue,
        ));
        if ($indexes === []) {
            $io->note('No index uses the "queue" sync mode; nothing to do.');

            return Command::SUCCESS;
        }
        $batch = is_string($input->getOption('batch')) ? max(1, (int) $input->getOption('batch')) : $this->batchSize;
        $this->worker = new Worker($this->fuzzphony->engine());

        if ($input->getOption('once') === true) {
            $processed = $this->worker->runOnce($indexes, $batch);
            $io->writeln(sprintf('Processed %d queued item(s).', $processed));

            return Command::SUCCESS;
        }

        $limit = $input->getOption('time-limit');
        $io->writeln(sprintf('Worker started for: %s (Ctrl+C / SIGTERM stops gracefully)', implode(', ', array_map(static fn ($i): string => $i->name, $indexes))));
        $total = $this->worker->run($indexes, $batch, $this->idleSleep, is_string($limit) ? (int) $limit : null, static function (int $processed) use ($output): void {
            if ($processed > 0 && $output->isVerbose()) {
                $output->writeln(sprintf('[%s] %d item(s)', date('H:i:s'), $processed));
            }
        });
        $io->writeln(sprintf('Stopped after %d item(s).', $total));

        return Command::SUCCESS;
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return \defined('SIGTERM') ? [\SIGTERM, \SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->worker?->stop();

        return false; // keep running until the current batch is finished
    }
}
