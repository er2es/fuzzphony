<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Sync;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;

/**
 * Batched, resumable backfill using keyset pagination over source ids.
 * Safe on live data: each batch is an idempotent upsert.
 */
final class Reindexer
{
    public function __construct(private readonly Engine $engine) {}

    /**
     * @param callable(int $processed, int|string $lastId): void|null $onBatch
     *
     * @return int total documents written
     */
    public function run(IndexDefinition $index, int $batchSize = 5_000, int|string|null $resumeAfter = null, ?callable $onBatch = null): int
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Batch size must be >= 1.');
        }
        $total = 0;
        $after = $resumeAfter;
        do {
            $ids = $this->engine->sourceIds($index, $after, $batchSize);
            if ($ids === []) {
                break;
            }
            $total += $this->engine->refresh($index, $ids);
            $after = $ids[array_key_last($ids)];
            if ($onBatch !== null) {
                $onBatch($total, $after);
            }
        } while (count($ids) === $batchSize);

        return $total;
    }
}
