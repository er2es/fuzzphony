<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

/**
 * "When a row of $table changes, which documents must be reindexed?"
 *
 * $affectedIds is a SELECT returning document ids; ":id" is replaced with the changed row's key.
 * Example (brand renamed -> its products): table "brand", affectedIds "SELECT id FROM product WHERE brand_id = :id".
 */
final readonly class Watch
{
    public function __construct(
        public string $table,
        public string $affectedIds = 'SELECT :id',
        public string $keyColumn = 'id',
    ) {}
}
