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
     *     option existed. Has no effect on the implicit, automatically-registered self-watch
     *     (the one Fuzzphony adds for a table source with no explicit .watch() call at all) —
     *     its relevant columns are always derived automatically from the index's own
     *     fields/filters/boost/recency. An explicit .watch($table, columns: [...]) call, even
     *     one naming the index's own source table, is a joined watch as far as this property is
     *     concerned: its explicit columns always take precedence over auto-derivation.
     */
    public function __construct(
        public string $table,
        public string $affectedIds = 'SELECT :id',
        public string $keyColumn = 'id',
        public ?array $columns = null,
    ) {}
}
