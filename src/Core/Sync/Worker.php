<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Sync;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\InvalidArgument;

/**
 * @internal Drains the sync queue. Run it long-lived (supervisor/systemd) or with runOnce() from cron
 * on hosts without long-running processes.
 */
final class Worker
{
    private bool $stop = false;
    private readonly Reindexer $reindexer;

    public function __construct(private readonly Engine $engine)
    {
        $this->reindexer = new Reindexer($engine);
    }

    /**
     * Processes every index until all queues are empty. A full rebuild a TRUNCATE requested runs
     * first and counts as one item.
     *
     * @param list<IndexDefinition> $indexes
     *
     * @return int processed items
     */
    public function runOnce(array $indexes, int $batchSize = 500): int
    {
        $total = 0;
        foreach ($indexes as $index) {
            $total += $this->rebuildIfRequested($index);
            while (($processed = $this->engine->processQueue($index, $batchSize)) > 0) {
                $total += $processed;
            }
        }

        return $total;
    }

    /**
     * A TRUNCATE that needs a full resync queued one rebuild. It runs like a full reindex, next to
     * the live index; the TRUNCATE established that the rows are gone, so an empty source empties
     * the index (pruneEmpty). While another run holds the index's rebuild lock the request stays
     * for the next cycle: that run may have read the table before the TRUNCATE. A rebuild that
     * fails after taking the request queues it again before the failure goes up.
     */
    private function rebuildIfRequested(IndexDefinition $index): int
    {
        if (!$this->engine->rebuildRequested($index)) {
            return 0;
        }
        try {
            $this->reindexer->run($index, new ReindexOptions(pruneEmpty: true));
        } catch (InvalidArgument) {
            return 0;
        } catch (\Throwable $e) {
            $this->engine->requestRebuild($index);

            throw $e;
        }

        return 1;
    }

    /**
     * @param list<IndexDefinition>                         $indexes
     * @param callable(int $processed): void|null $onCycle
     */
    public function run(array $indexes, int $batchSize = 500, float $idleSleepSeconds = 1.0, ?int $timeLimitSeconds = null, ?callable $onCycle = null): int
    {
        $started = microtime(true);
        $total = 0;
        $this->stop = false;
        while ($this->keepRunning()) {
            $processed = $this->runOnce($indexes, $batchSize);
            $total += $processed;
            if ($onCycle !== null) {
                $onCycle($processed);
            }
            if ($timeLimitSeconds !== null && microtime(true) - $started >= $timeLimitSeconds) {
                break;
            }
            if ($processed === 0) {
                usleep((int) ($idleSleepSeconds * 1_000_000));
            }
        }

        return $total;
    }

    /** Graceful shutdown (e.g. from a SIGTERM handler): finishes the current batch. */
    public function stop(): void
    {
        $this->stop = true;
    }

    /** Behind a method call so PHPStan does not "prove" this loop condition constant: $stop is mutated asynchronously by a signal handler. */
    private function keepRunning(): bool
    {
        return !$this->stop;
    }
}
