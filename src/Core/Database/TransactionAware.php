<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Database;

/** Optional Connection capability: lets the engine skip work that a surrounding transaction's own commit already undoes. */
interface TransactionAware
{
    /** True when a transaction is already open on this connection (the caller's, not one Fuzzphony itself is about to start). */
    public function inTransaction(): bool;
}
