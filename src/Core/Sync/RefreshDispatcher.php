<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Sync;

use Fuzzphony\Core\Definition\IndexDefinition;

/** Decides WHEN changed documents are refreshed: right away, or later through a message bus. */
interface RefreshDispatcher
{
    /** @param list<int|string> $ids */
    public function dispatch(IndexDefinition $index, array $ids): void;
}
