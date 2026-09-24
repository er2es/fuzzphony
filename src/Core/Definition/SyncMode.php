<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

/** How the sidecar index follows changes of the source data. */
enum SyncMode: string
{
    /** Application-level: Doctrine ORM lifecycle events refresh the index. */
    case Orm = 'orm';
    /** Database triggers refresh the index synchronously, inside the writing transaction. */
    case Trigger = 'trigger';
    /** Database triggers only enqueue ids; a worker refreshes them in batches (default). */
    case Queue = 'queue';
    /** No automatic sync; run the reindex command yourself. */
    case Manual = 'manual';

    public function usesTriggers(): bool
    {
        return $this === self::Trigger || $this === self::Queue;
    }
}
