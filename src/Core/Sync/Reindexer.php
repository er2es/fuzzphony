<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Sync;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;

/**
 * Batched, resumable backfill using keyset pagination over source ids.
 * Safe on live data: each batch is an idempotent upsert.
 *
 * A full run (no ReindexOptions::$resumeAfter) finally removes the indexed documents the source no
 * longer returns (orphans); a resumed run only covers part of the source, so it leaves them alone.
 * Pruning is relative to what THIS session sees: where it sees fewer rows than the application
 * (row-level security, a query source using current_setting(), another search_path) the difference
 * is removed, so pass ReindexOptions::$prune = false there. A full run that found no source row at
 * all skips pruning unless ReindexOptions::$pruneEmpty is set: an empty source is far more likely a
 * visibility problem than intent (and a genuine TRUNCATE is already handled by the TRUNCATE trigger).
 */
final class Reindexer
{
    public function __construct(private readonly Engine $engine) {}

    public function run(IndexDefinition $index, ReindexOptions $options): ReindexResult
    {
        $written = 0;
        $seen = 0;
        $after = $options->resumeAfter;
        do {
            $ids = $this->engine->sourceIds($index, $after, $options->batchSize);
            if ($ids === []) {
                break;
            }
            $seen += count($ids);
            $written += $this->engine->refresh($index, $ids);
            $after = $ids[array_key_last($ids)];
            if ($options->onBatch !== null) {
                ($options->onBatch)($written, $after);
            }
        } while (count($ids) === $options->batchSize);

        if ($options->resumeAfter !== null || !$options->prune) {
            return new ReindexResult($written);
        }
        if ($seen === 0 && !$options->pruneEmpty) {
            return new ReindexResult($written, pruneSkippedEmptySource: true);
        }

        return new ReindexResult($written, $this->engine->pruneOrphans($index, $options->batchSize));
    }
}
