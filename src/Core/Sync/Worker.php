<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Sync;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\RebuildAlreadyRunning;
use Fuzzphony\Core\Observability\MetricsCollector;
use Fuzzphony\Core\Observability\NullMetricsCollector;

/**
 * @internal Drains the sync queue. Run it long-lived (supervisor/systemd) or with runOnce() from cron
 * on hosts without long-running processes.
 */
final class Worker
{
    /** Seconds before a failed rebuild job is tried again by the same worker; doubles per failure in a row, up to BACKOFF_MAX. */
    private const int BACKOFF = 60;
    private const int BACKOFF_MAX = 3_600;

    private bool $stop = false;
    private readonly Reindexer $reindexer;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var array<string, array{int, float}> the current back-off (seconds) and when to try again, by index name */
    private array $backoff = [];

    /** @var array<string, \Throwable> */
    private array $failures = [];

    /** @param (\Closure(): float)|null $clock seconds, for the rebuild back-off (default microtime(true)) */
    public function __construct(
        private readonly Engine $engine,
        ?\Closure $clock = null,
        private readonly MetricsCollector $metrics = new NullMetricsCollector(),
    ) {
        $this->reindexer = new Reindexer($engine);
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /**
     * Processes every index until all queues are empty. A full rebuild a TRUNCATE requested runs
     * first and counts as one item. A rebuild that fails never stops the worker: the job stays
     * queued, the failure is recorded for the doctor and listed by rebuildFailures(), and the
     * index's queued ids and the other indexes are processed as usual.
     *
     * @param list<IndexDefinition> $indexes
     *
     * @return int processed items
     */
    public function runOnce(array $indexes, int $batchSize = 500): int
    {
        $this->failures = [];
        $total = 0;
        foreach ($indexes as $index) {
            $total += $this->rebuildIfRequested($index);
            $this->metrics->gauge('fuzzphony.queue.depth', (float) $this->engine->queueSize($index), ['index' => $index->name]);
            $drained = 0;
            while (($processed = $this->engine->processQueue($index, $batchSize)) > 0) {
                $drained += $processed;
            }
            if ($drained > 0) {
                $this->metrics->increment('fuzzphony.queue.processed', ['index' => $index->name], $drained);
            }
            $total += $drained;
        }

        return $total;
    }

    /**
     * The rebuilds that failed in the last runOnce() (or run() cycle), by index name.
     *
     * @return array<string, \Throwable>
     */
    public function rebuildFailures(): array
    {
        return $this->failures;
    }

    /**
     * A TRUNCATE that needs a full resync queued one rebuild. It runs like a full reindex, next to
     * the live index; the TRUNCATE established that the rows are gone, so an empty source empties
     * the index (pruneEmpty). While another run holds the index's rebuild lock the request stays
     * for the next cycle: that run may have read the table before the TRUNCATE. After a failure
     * this worker waits BACKOFF seconds before it tries that index's job again, doubling per
     * failure in a row.
     */
    private function rebuildIfRequested(IndexDefinition $index): int
    {
        if (($this->backoff[$index->name][1] ?? 0.0) > ($this->clock)() || !$this->engine->rebuildRequested($index)) {
            return 0;
        }
        try {
            $this->reindexer->run($index, new ReindexOptions(pruneEmpty: true));
        } catch (RebuildAlreadyRunning) {
            return 0;
        } catch (\Throwable $e) {
            $this->failed($index, $e);

            return 0;
        }
        unset($this->backoff[$index->name]);

        return 1;
    }

    private function failed(IndexDefinition $index, \Throwable $e): void
    {
        $this->failures[$index->name] = $e;
        $this->metrics->increment('fuzzphony.worker.rebuild_failures', ['index' => $index->name]);
        $delay = isset($this->backoff[$index->name]) ? min(self::BACKOFF_MAX, 2 * $this->backoff[$index->name][0]) : self::BACKOFF;
        $this->backoff[$index->name] = [$delay, ($this->clock)() + $delay];
        try {
            $this->engine->recordRebuildFailure($index, $e->getMessage());
        } catch (\Throwable) {
            // the rebuild's failure is the one to report
        }
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
