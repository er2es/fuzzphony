<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Schema;

final readonly class Statement
{
    public function __construct(
        public string $sql,
        public string $description,
        /** False for statements that cannot run inside a transaction (e.g. CREATE INDEX CONCURRENTLY). */
        public bool $transactional = true,
        /**
         * A query that returns identifiers in a column `name`: the statement is then run once for each, with `{name}` in its SQL
         * replaced by it (decided when the plan is applied; --dump-migration leaves such a statement out). A failure of one
         * is reported as a warning and the plan goes on.
         */
        public ?string $forEach = null,
        /**
         * A query that returns true when the statement has nothing to do (the index it builds exists already): it is then not run.
         * For a concurrent build that IF NOT EXISTS would skip anyway, but only after it queued for the table lock behind another
         * build (two applies at once: the builds wait for each other and one fails with a deadlock).
         */
        public ?string $skipIf = null,
        /** A query that returns true while the statement has to wait (another session builds an index on the same table); polled before it runs. */
        public ?string $waitWhile = null,
    ) {}
}
