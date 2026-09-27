<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Sync;

use Fuzzphony\Core\Exception\InvalidArgument;

/**
 * How Fuzzphony::reindex() runs. Pruning is relative to what THIS session sees: where it sees fewer
 * rows than the application (row-level security, a query source using current_setting(), another
 * search_path), pass prune: false. A full run whose source returns no row skips pruning unless
 * pruneEmpty is set (an empty source is far more likely a visibility problem than intent).
 */
final readonly class ReindexOptions
{
    /** @param (\Closure(int $processed, int|string $lastId): void)|null $onBatch called after every batch */
    public function __construct(
        public int $batchSize = 5_000,
        /** Resume after this source id (printed while running); a resumed run never prunes. */
        public int|string|null $resumeAfter = null,
        public bool $prune = true,
        public bool $pruneEmpty = false,
        public ?\Closure $onBatch = null,
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgument(sprintf('Batch size must be >= 1, got %d.', $batchSize));
        }
    }
}
