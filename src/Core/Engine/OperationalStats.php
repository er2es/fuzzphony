<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Engine;

use Fuzzphony\Core\Definition\IndexDefinition;

/**
 * @internal What an engine can say about the health of an index's sync objects for the metrics gauges, one cheap statement each
 * (counts on the small change log and queue tables, never a scan of a big one). Implemented by the PostgreSQL engine; the worker
 * reports it next to the queue depth.
 */
interface OperationalStats
{
    /**
     * Gauge name => value: `fuzzphony.watch_log.entries`, `fuzzphony.watch_log.oldest_age_seconds`, `fuzzphony.watch.bad_rows`,
     * `fuzzphony.queue.failing_items` and `fuzzphony.queue.oldest_age_seconds`. Never throws: what cannot be read is left out.
     *
     * @return array<string, float>
     */
    public function operationalStats(IndexDefinition $index): array;
}
