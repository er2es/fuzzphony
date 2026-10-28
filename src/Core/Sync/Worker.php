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

    /** Batches one index may take in a row before the next index has its turn. */
    private const int MAX_BATCHES_PER_TURN = 10;

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
     * index's queued ids and the other indexes are processed as usual. The indexes take turns of
     * at most MAX_BATCHES_PER_TURN batches, so a busy index never starves the others, and stop()
     * ends the drain after the batch in progress.
     *
     * @param list<IndexDefinition> $indexes
     *
     * @return int processed items
     */
    public function runOnce(array $indexes, int $batchSize = 500): int
    {
        return $this->drain($indexes, $batchSize, null, true);
    }

    /**
     * @param list<IndexDefinition> $indexes
     * @param float|null            $deadline   when ($this->clock)() reaches it, the drain ends after the batch in progress
     * @param bool                  $untilEmpty false: one turn per index (a run() cycle), so a busy index cannot keep the cycle from
     *                                          looking for requested rebuilds and reporting; true: until every queue is empty
     */
    private function drain(array $indexes, int $batchSize, ?float $deadline, bool $untilEmpty): int
    {
        $this->failures = [];
        $total = 0;
        /** @var array<string, IndexDefinition> $busy the indexes whose turn ended with work possibly left */
        $busy = [];
        /** @var array<string, int> $drained */
        $drained = [];
        $halt = false;
        foreach ($indexes as $index) {
            if ($halt || !$this->keepRunning()) {
                break;
            }
            $total += $this->rebuildIfRequested($index);
            $this->metrics->gauge('fuzzphony.queue.depth', (float) $this->engine->queueSize($index), ['index' => $index->name]);
            $drained[$index->name] = 0;
            $busy[$index->name] = $index;
            [$done, $halt] = $this->turn($index, $batchSize, $deadline, $busy, $drained);
            $total += $done;
        }
        while ($untilEmpty && $busy !== [] && !$halt && $this->keepRunning()) {
            foreach ($busy as $index) {
                [$done, $halt] = $this->turn($index, $batchSize, $deadline, $busy, $drained);
                $total += $done;
                if ($halt) {
                    break;
                }
            }
        }
        foreach ($drained as $name => $count) {
            if ($count > 0) {
                $this->metrics->increment('fuzzphony.queue.processed', ['index' => $name], $count);
            }
        }

        return $total;
    }

    /**
     * Up to MAX_BATCHES_PER_TURN batches of one index; an index whose queue turned out empty leaves $busy.
     * Stop and the deadline are checked after a batch that did work (so every run makes progress,
     * even with a time limit of 0); the second value is true when they say stop.
     *
     * @param array<string, IndexDefinition> $busy
     * @param array<string, int>             $drained
     *
     * @return array{int, bool} the items processed and whether the drain must end
     */
    private function turn(IndexDefinition $index, int $batchSize, ?float $deadline, array &$busy, array &$drained): array
    {
        $turn = 0;
        for ($batches = 0; $batches < self::MAX_BATCHES_PER_TURN; ++$batches) {
            $processed = $this->engine->processQueue($index, $batchSize);
            if ($processed <= 0) {
                unset($busy[$index->name]);

                break;
            }
            $turn += $processed;
            $drained[$index->name] += $processed;
            if (!$this->mayContinue($deadline)) {
                return [$turn, true];
            }
        }

        return [$turn, false];
    }

    private function mayContinue(?float $deadline): bool
    {
        return $this->keepRunning() && ($deadline === null || ($this->clock)() < $deadline);
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
        $deadline = $timeLimitSeconds === null ? null : ($this->clock)() + $timeLimitSeconds;
        $total = 0;
        $this->stop = false;
        while ($this->keepRunning()) {
            $processed = $this->drain($indexes, $batchSize, $deadline, false);
            $total += $processed;
            if ($onCycle !== null) {
                $onCycle($processed);
            }
            if ($deadline !== null && ($this->clock)() >= $deadline) {
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
