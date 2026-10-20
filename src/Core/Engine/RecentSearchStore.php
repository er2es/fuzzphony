<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Engine;

use Fuzzphony\Core\Analytics\LoggedSearch;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Definition\IndexDefinition;

/**
 * An optional capability of a SearchLogStore: it can read single searches back (SearchAnalytics::recentSearches()).
 * A store without it keeps working for everything else; recentSearches() then refuses.
 */
interface RecentSearchStore
{
    /**
     * The most recent logged searches of $index from $from until $to (null = now), newest first, whatever their status.
     * The tenant arguments work as in SearchLogStore::topQueries(). $user keeps the searches of that user,
     * $anonymousOnly those without a user (not both), $status those that ended so.
     *
     * @return list<LoggedSearch>
     */
    public function recentSearches(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants, ?string $user, bool $anonymousOnly, ?RecordStatus $status): array;
}
