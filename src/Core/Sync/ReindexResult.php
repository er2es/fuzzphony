<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Sync;

/** What a reindex did. */
final readonly class ReindexResult
{
    public function __construct(
        /** Documents written. */
        public int $written,
        /** Orphaned documents removed; null when pruning did not run (resumed run, prune: false, empty source). */
        public ?int $pruned = null,
        /** True when a full run found no source row and therefore did not prune (see ReindexOptions::$pruneEmpty). */
        public bool $pruneSkippedEmptySource = false,
    ) {}
}
