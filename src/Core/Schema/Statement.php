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
    ) {}
}
