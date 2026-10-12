<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Analytics;

use Fuzzphony\Core\Analytics\SearchLog;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Engine\SearchLogStore;
use Fuzzphony\Core\Observability\MetricsCollector;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal Holds the records of a request (or a console command) and writes them in one statement
 * after the response is sent (SearchLogFlushListener), so a search never waits for its own log line.
 *
 * A long-running process (messenger:consume, FrankenPHP, RoadRunner) never reaches a kernel.terminate that ends it,
 * so the buffer is also written when it reaches {@see self::MAX_BUFFERED} records and on the container's reset
 * (kernel.reset runs between messages and requests). A process killed without a chance to flush loses what it holds.
 */
final class BufferedSearchLog implements SearchLog, ResetInterface
{
    /** The records held at most; the one that reaches it triggers a write. */
    public const int MAX_BUFFERED = 1000;

    /** @var list<SearchRecord> */
    private array $buffer = [];

    /** @var SearchLogStore|null the resolved store (null until the first write when $store is a closure) */
    private ?SearchLogStore $resolved = null;

    /** @param SearchLogStore|\Closure(): SearchLogStore $store a closure is called on the first write, so a request that logged nothing never builds the engine behind it */
    public function __construct(
        private readonly SearchLogStore|\Closure $store,
        private readonly MetricsCollector $metrics,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function record(SearchRecord $record): void
    {
        $this->buffer[] = $record;
        if (count($this->buffer) >= self::MAX_BUFFERED) {
            $this->flush();
        }
    }

    /** kernel.reset: between two messages or requests of a long-running process. */
    public function reset(): void
    {
        $this->flush();
    }

    /** Writes everything buffered in one call; never throws (a lost batch is counted and logged, not retried). */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $batch = $this->buffer;
        $this->buffer = [];
        try {
            $this->store()->writeSearchLog($batch);
        } catch (\Throwable $e) {
            $this->metrics->increment('fuzzphony.search_log.failed', [], count($batch));
            $this->logger?->error('Fuzzphony could not write {count} search log records: {message}', ['count' => count($batch), 'message' => $e->getMessage()]);
        }
    }

    private function store(): SearchLogStore
    {
        return $this->resolved ??= $this->store instanceof SearchLogStore ? $this->store : ($this->store)();
    }
}
