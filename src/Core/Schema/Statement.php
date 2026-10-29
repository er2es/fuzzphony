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
    ) {}
}
