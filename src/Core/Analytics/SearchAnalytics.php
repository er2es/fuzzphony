<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Analytics;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\RecentSearchStore;
use Fuzzphony\Core\Engine\SearchLogStore;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Registry\IndexRegistry;

/** Reports over the search log, and its purge. Get it from Fuzzphony::analytics(). */
final readonly class SearchAnalytics
{
    public function __construct(private SearchLogStore $store, private IndexRegistry $registry) {}

    /**
     * The most searched texts of an index since $from (until $to), most searched first.
     *
     * @param int $limit 1 to 1000
     *
     * @return list<QueryStat>
     *
     * @throws InvalidArgument for a limit outside 1 to 1000, a tenant together with allTenants, or a tenant-scoped index without a tenant or allTenants
     */
    public function topQueries(string $index, \DateTimeInterface $from, ?\DateTimeInterface $to = null, int $limit = 20, int|string|null $tenant = null, bool $allTenants = false): array
    {
        return $this->store->topQueries(...$this->arguments($index, $from, $to, $limit, $tenant, $allTenants));
    }

    /**
     * The texts that found nothing, most searched first; the same arguments as topQueries().
     *
     * @param int $limit 1 to 1000
     *
     * @return list<QueryStat>
     *
     * @throws InvalidArgument as topQueries()
     */
    public function zeroResultQueries(string $index, \DateTimeInterface $from, ?\DateTimeInterface $to = null, int $limit = 20, int|string|null $tenant = null, bool $allTenants = false): array
    {
        return $this->store->zeroResultQueries(...$this->arguments($index, $from, $to, $limit, $tenant, $allTenants));
    }

    /**
     * The most recent logged searches of an index since $from (until $to), newest first: the stored text (already
     * redacted), who searched, how it went. Searches that were refused (denied, rate_limited) are included. A per-user
     * history is personal data: see SECURITY.md. The tenant rule is the one of topQueries(): a tenant-scoped index
     * needs a tenant, or allTenants: true.
     *
     * @param int               $limit         1 to 1000
     * @param string|null       $user          only the searches of this user (exactly this name)
     * @param bool              $anonymousOnly only the searches without a user; not together with $user
     * @param RecordStatus|null $status       only the searches that ended so
     *
     * @return list<LoggedSearch>
     *
     * @throws InvalidArgument as topQueries(), for an empty user or a user together with anonymousOnly, and when the
     *                         search log store does not implement RecentSearchStore
     */
    public function recentSearches(string $index, \DateTimeInterface $from, ?\DateTimeInterface $to = null, int $limit = 50, int|string|null $tenant = null, bool $allTenants = false, ?string $user = null, bool $anonymousOnly = false, ?RecordStatus $status = null): array
    {
        if ($user === '') {
            throw new InvalidArgument('Name a user, or pass anonymousOnly: true for the searches without one.');
        }
        if ($user !== null && $anonymousOnly) {
            throw new InvalidArgument('Name a user or pass anonymousOnly: true, not both.');
        }

        if (!$this->store instanceof RecentSearchStore) {
            throw new InvalidArgument(sprintf('This search log store cannot list single searches: it does not implement %s.', RecentSearchStore::class));
        }
        [$definition, $from, $to, $limit, $tenant, $allTenants] = $this->arguments($index, $from, $to, $limit, $tenant, $allTenants);

        // positionally: an implementation may name its parameters differently
        return $this->store->recentSearches($definition, $from, $to, $limit, $tenant, $allTenants, $user, $anonymousOnly, $status);
    }

    /** Deletes the log records older than $before; returns how many. */
    public function purge(\DateTimeInterface $before): int
    {
        return $this->store->purgeSearchLog($before);
    }

    /** @return array{IndexDefinition, \DateTimeInterface, ?\DateTimeInterface, int, int|string|null, bool} */
    private function arguments(string $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
    {
        $definition = $this->registry->get($index);
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgument(sprintf('The limit must be between 1 and 1000, got %d.', $limit));
        }
        if ($tenant !== null && $allTenants) {
            throw new InvalidArgument('Name a tenant or pass allTenants: true, not both.');
        }
        if ($definition->tenant !== null && $tenant === null && !$allTenants) {
            throw new InvalidArgument(sprintf('Index "%s" is tenant-scoped: name a tenant, or pass allTenants: true for a report across tenants.', $index));
        }

        // a tenant filters on any index: the gate records the actor's tenant on an index that is not tenant-scoped too
        return [$definition, $from, $to, $limit, $tenant, $allTenants];
    }
}
