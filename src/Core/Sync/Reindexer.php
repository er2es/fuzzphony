<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Sync;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;

/**
 * Batched, resumable backfill using keyset pagination over source ids.
 * Safe on live data: each batch is an idempotent upsert.
 *
 * A full run (no $resumeAfter) finally removes the indexed documents the source no longer returns
 * (orphans); a resumed run only covers part of the source, so it leaves them alone. Pruning is
 * relative to what THIS session sees: where it sees fewer rows than the application (row-level
 * security, a query source using current_setting(), another search_path) the difference is removed,
 * so pass $prune = false there. A full run that found no source row at all skips pruning unless
 * $pruneEmpty is set: an empty source is far more likely a visibility problem than intent (and a
 * genuine TRUNCATE is already handled by the TRUNCATE trigger).
 */
final class Reindexer
{
    public function __construct(private readonly Engine $engine) {}

    /**
     * @param callable(int $processed, int|string $lastId): void|null $onBatch
     * @param callable(int $removed): void|null                         $onPruned        called after a full run's orphan pruning
     * @param callable(): void|null                                     $onPruneSkipped  called when a full run found no source row and $pruneEmpty is not set
     *
     * @return int total documents written
     */
    public function run(
        IndexDefinition $index,
        int $batchSize = 5_000,
        int|string|null $resumeAfter = null,
        ?callable $onBatch = null,
        ?callable $onPruned = null,
        bool $prune = true,
        bool $pruneEmpty = false,
        ?callable $onPruneSkipped = null,
    ): int {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Batch size must be >= 1.');
        }
        $total = 0;
        $seen = 0;
        $after = $resumeAfter;
        do {
            $ids = $this->engine->sourceIds($index, $after, $batchSize);
            if ($ids === []) {
                break;
            }
            $seen += count($ids);
            $total += $this->engine->refresh($index, $ids);
            $after = $ids[array_key_last($ids)];
            if ($onBatch !== null) {
                $onBatch($total, $after);
            }
        } while (count($ids) === $batchSize);

        if ($resumeAfter !== null || !$prune) {
            return $total;
        }
        if ($seen === 0 && !$pruneEmpty) {
            if ($onPruneSkipped !== null) {
                $onPruneSkipped();
            }

            return $total;
        }
        $removed = $this->engine->pruneOrphans($index, $batchSize);
        if ($onPruned !== null) {
            $onPruned($removed);
        }

        return $total;
    }
}
