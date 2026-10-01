<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Sync;

use Fuzzphony\Core\Exception\InvalidArgument;

/**
 * How Fuzzphony::reindex() runs. A full run builds the index next to the live one and swaps it in,
 * so searches never see a half-built index; it writes the live index in place instead (as before
 * 0.5) with inPlace, with prune: false, or when the engine or the role cannot build next to it.
 * Pruning is relative to what THIS session sees: where it sees fewer rows than the application
 * (row-level security, a query source using current_setting(), another search_path), pass
 * prune: false. A full run whose source returns no row keeps the live index unless pruneEmpty is
 * set (an empty source is far more likely a visibility problem than intent).
 */
final readonly class ReindexOptions
{
    /** @param (\Closure(int $processed, int|string $lastId): void)|null $onBatch called after every batch */
    public function __construct(
        public int $batchSize = 5_000,
        /** Resume after this source id (printed while running): continues the rebuild a failed run left behind, else writes in place without pruning. */
        public int|string|null $resumeAfter = null,
        public bool $prune = true,
        public bool $pruneEmpty = false,
        public ?\Closure $onBatch = null,
        /** Write the live index directly: no second copy on disk, but searches see a mix of old and new documents while it runs. */
        public bool $inPlace = false,
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgument(sprintf('Batch size must be >= 1, got %d.', $batchSize));
        }
    }
}
