<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Engine;

use Fuzzphony\Core\Analytics\QueryStat;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Definition\IndexDefinition;

/**
 * An optional capability of an engine: it keeps the search log in its own store and reports on it.
 * An engine that does not implement it keeps no log (Fuzzphony::analytics() refuses).
 */
interface SearchLogStore
{
    /**
     * @param list<SearchRecord> $records
     *
     * @return int the number of records written
     */
    public function writeSearchLog(array $records): int;

    /** @return int the number of records deleted */
    public function purgeSearchLog(\DateTimeInterface $before): int;

    /**
     * The most searched texts of $index from $from until $to (null = now), most searched first. A
     * tenant-scoped index is reported for $tenant, or across tenants when $allTenants.
     *
     * @return list<QueryStat>
     */
    public function topQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array;

    /**
     * The texts that found nothing, with the same arguments as topQueries().
     *
     * @return list<QueryStat>
     */
    public function zeroResultQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array;
}
