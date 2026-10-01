<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Sync;

/** What a reindex did. */
final readonly class ReindexResult
{
    public function __construct(
        /** Documents written. */
        public int $written,
        /** Orphaned documents removed in place; null when pruning did not run in place (resumed run, prune: false, empty source, or a swap: the orphans went with the old index). */
        public ?int $pruned = null,
        /** True when a full run found no source row and therefore kept the index (see ReindexOptions::$pruneEmpty). */
        public bool $pruneSkippedEmptySource = false,
        /** True when the run was built next to the live index and swapped in. */
        public bool $swapped = false,
    ) {}
}
