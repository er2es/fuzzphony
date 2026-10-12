<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Command;

use Fuzzphony\Core\Fuzzphony;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal The fuzzphony:search-log:purge console command; its CLI is public, the class is not. */
#[AsCommand(name: 'fuzzphony:search-log:purge', description: 'Delete the search log rows older than fuzzphony.search_log.retention_days')]
final class SearchLogPurgeCommand extends Command
{
    public function __construct(private readonly Fuzzphony $fuzzphony, private readonly int $retentionDays)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->fuzzphony->analytics()->purge(new \DateTimeImmutable(sprintf('-%d days', $this->retentionDays)));
        (new SymfonyStyle($input, $output))->success(sprintf('Deleted %d search log rows older than %d days.', $deleted, $this->retentionDays));

        return Command::SUCCESS;
    }
}
