<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Analytics;

use Fuzzphony\Core\Analytics\SearchLog;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Engine\SearchLogStore;
use Fuzzphony\Core\Observability\MetricsCollector;
use Psr\Log\LoggerInterface;

/**
 * @internal Holds the records of a request (or a console command) and writes them in one statement
 * after the response is sent (SearchLogFlushListener), so a search never waits for its own log line.
 */
final class BufferedSearchLog implements SearchLog
{
    /** @var list<SearchRecord> */
    private array $buffer = [];

    public function __construct(
        private readonly SearchLogStore $store,
        private readonly MetricsCollector $metrics,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function record(SearchRecord $record): void
    {
        $this->buffer[] = $record;
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
            $this->store->writeSearchLog($batch);
        } catch (\Throwable $e) {
            $this->metrics->increment('fuzzphony.search_log.failed', [], count($batch));
            $this->logger?->error('Fuzzphony could not write {count} search log records: {message}', ['count' => count($batch), 'message' => $e->getMessage()]);
        }
    }
}
