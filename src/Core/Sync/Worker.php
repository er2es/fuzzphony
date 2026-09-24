<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Sync;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;

/**
 * Drains the sync queue. Run it long-lived (supervisor/systemd) or with runOnce() from cron
 * on hosts without long-running processes.
 */
final class Worker
{
    private bool $stop = false;

    public function __construct(private readonly Engine $engine) {}

    /**
     * Processes every index until all queues are empty.
     *
     * @param list<IndexDefinition> $indexes
     *
     * @return int processed items
     */
    public function runOnce(array $indexes, int $batchSize = 500): int
    {
        $total = 0;
        foreach ($indexes as $index) {
            while (($processed = $this->engine->processQueue($index, $batchSize)) > 0) {
                $total += $processed;
            }
        }

        return $total;
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
