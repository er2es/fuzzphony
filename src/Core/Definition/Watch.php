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
    /**
     * @param list<string>|null $columns Restrict UPDATE-triggered refreshes to changes in these
     *     columns of $table; null (the default) refreshes on every UPDATE, same as before this
     *     option existed. Ignored for the automatic self-watch on the index's own source table,
     *     where the relevant columns are derived automatically from its fields/filters/boost/recency.
     */
    public function __construct(
        public string $table,
        public string $affectedIds = 'SELECT :id',
        public string $keyColumn = 'id',
        public ?array $columns = null,
    ) {}
}
