<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Analytics;

use Fuzzphony\Core\Analytics\LoggedSearch;
use Fuzzphony\Core\Analytics\QueryStat;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Analytics\SearchAnalytics;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Engine\RecentSearchStore;
use Fuzzphony\Core\Engine\SearchLogStore;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchAnalyticsTest extends TestCase
{
    private function analytics(bool $tenant, FakeSearchLogStore $store): SearchAnalytics
    {
        return new SearchAnalytics($store, new IndexRegistry([Indexes::products(tenant: $tenant)]));
    }

    public function testATenantScopedIndexNeedsATenantOrAllTenants(): void
    {
        $analytics = $this->analytics(true, new FakeSearchLogStore());

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('allTenants');
        $analytics->topQueries('products', new \DateTimeImmutable('-7 days'));
    }

    public function testATenantIsPassedToTheStore(): void
    {
        $store = new FakeSearchLogStore();
        $from = new \DateTimeImmutable('-7 days');

        $result = $this->analytics(true, $store)->topQueries('products', $from, null, 5, 3);

        self::assertSame($store->stats, $result);
        self::assertSame([['top', 'products', $from, null, 5, 3, false]], $store->calls);
    }

    public function testAllTenantsIsPassedToTheStore(): void
    {
        $store = new FakeSearchLogStore();
        $from = new \DateTimeImmutable('-7 days');
        $to = new \DateTimeImmutable('-1 day');

        $this->analytics(true, $store)->zeroResultQueries('products', $from, $to, allTenants: true);

        self::assertSame([['zero', 'products', $from, $to, 20, null, true]], $store->calls);
    }

    public function testAStringTenantIsPassedToTheStore(): void
    {
        $store = new FakeSearchLogStore();
        $from = new \DateTimeImmutable('-7 days');

        $this->analytics(true, $store)->zeroResultQueries('products', $from, tenant: 'acme');

        self::assertSame([['zero', 'products', $from, null, 20, 'acme', false]], $store->calls);
    }

    /** The gate records the actor's tenant on an index that is not tenant-scoped too: a per-customer report on a shared index must not show everyone's searches. */
    public function testATenantFiltersAnIndexThatIsNotTenantScopedToo(): void
    {
        $store = new FakeSearchLogStore();
        $from = new \DateTimeImmutable('-7 days');

        $analytics = $this->analytics(false, $store);
        $analytics->topQueries('products', $from, tenant: 3);
        $analytics->zeroResultQueries('products', $from, tenant: 'acme');

        self::assertSame([['top', 'products', $from, null, 20, 3, false], ['zero', 'products', $from, null, 20, 'acme', false]], $store->calls);
    }

    public function testAnIndexWithoutATenantStillNeedsNeither(): void
    {
        $store = new FakeSearchLogStore();
        $from = new \DateTimeImmutable('-7 days');

        $analytics = $this->analytics(false, $store);
        $analytics->topQueries('products', $from);
        $analytics->zeroResultQueries('products', $from, allTenants: true);

        self::assertSame([['top', 'products', $from, null, 20, null, false], ['zero', 'products', $from, null, 20, null, true]], $store->calls);
    }

    /** @return iterable<string, array{bool}> */
    public static function scoped(): iterable
    {
        yield 'tenant-scoped index' => [true];
        yield 'index without a tenant' => [false];
    }

    #[DataProvider('scoped')]
    public function testATenantTogetherWithAllTenantsIsRefused(bool $tenantScoped): void
    {
        $store = new FakeSearchLogStore();
        $analytics = $this->analytics($tenantScoped, $store);

        $calls = [
            'topQueries' => static fn() => $analytics->topQueries('products', new \DateTimeImmutable('-7 days'), tenant: 3, allTenants: true),
            'zeroResultQueries' => static fn() => $analytics->zeroResultQueries('products', new \DateTimeImmutable('-7 days'), tenant: 3, allTenants: true),
        ];
        foreach ($calls as $method => $call) {
            try {
                $call();
                self::fail('Expected an InvalidArgument from ' . $method);
            } catch (InvalidArgument $e) {
                self::assertSame('Name a tenant or pass allTenants: true, not both.', $e->getMessage());
            }
        }
        self::assertSame([], $store->calls, 'nothing reached the store');
    }

    /** @return iterable<string, array{int, bool}> */
    public static function limits(): iterable
    {
        yield '0' => [0, false];
        yield '1' => [1, true];
        yield '1000' => [1000, true];
        yield '1001' => [1001, false];
    }

    #[DataProvider('limits')]
    public function testTheLimitIsOneToAThousand(int $limit, bool $allowed): void
    {
        $store = new FakeSearchLogStore();
        $analytics = $this->analytics(false, $store);
        if (!$allowed) {
            $this->expectException(InvalidArgument::class);
            $this->expectExceptionMessage(sprintf('The limit must be between 1 and 1000, got %d.', $limit));
        }

        $analytics->topQueries('products', new \DateTimeImmutable(), limit: $limit);

        self::assertCount(1, $store->calls);
    }

    public function testRecentSearchesPassEveryArgumentToTheStore(): void
    {
        $store = new FakeSearchLogStore();
        $from = new \DateTimeImmutable('-7 days');
        $to = new \DateTimeImmutable('-1 day');

        $result = $this->analytics(true, $store)->recentSearches('products', $from, $to, 5, 'acme', false, 'ada', false, RecordStatus::Denied);

        self::assertSame($store->logged, $result);
        self::assertSame([['recent', 'products', $from, $to, 5, 'acme', false, 'ada', false, RecordStatus::Denied]], $store->calls);
    }

    public function testRecentSearchesDefaults(): void
    {
        $store = new FakeSearchLogStore();
        $from = new \DateTimeImmutable('-7 days');

        $this->analytics(false, $store)->recentSearches('products', $from);

        self::assertSame([['recent', 'products', $from, null, 50, null, false, null, false, null]], $store->calls);
    }

    public function testRecentSearchesNeedATenantOnATenantScopedIndex(): void
    {
        $store = new FakeSearchLogStore();
        $analytics = $this->analytics(true, $store);

        try {
            $analytics->recentSearches('products', new \DateTimeImmutable('-7 days'), user: 'ada');
            self::fail('Expected an InvalidArgument');
        } catch (InvalidArgument $e) {
            self::assertStringContainsString('is tenant-scoped', $e->getMessage());
            self::assertStringContainsString('allTenants', $e->getMessage());
        }
        self::assertSame([], $store->calls, 'nothing reached the store');
    }

    public function testRecentSearchesAcrossTenantsAreExplicit(): void
    {
        $store = new FakeSearchLogStore();
        $from = new \DateTimeImmutable('-7 days');

        $this->analytics(true, $store)->recentSearches('products', $from, allTenants: true, user: 'ada');

        self::assertSame([['recent', 'products', $from, null, 50, null, true, 'ada', false, null]], $store->calls);
    }

    #[DataProvider('scoped')]
    public function testRecentSearchesRefuseATenantTogetherWithAllTenants(bool $tenantScoped): void
    {
        $store = new FakeSearchLogStore();

        try {
            $this->analytics($tenantScoped, $store)->recentSearches('products', new \DateTimeImmutable('-7 days'), tenant: 3, allTenants: true);
            self::fail('Expected an InvalidArgument');
        } catch (InvalidArgument $e) {
            self::assertSame('Name a tenant or pass allTenants: true, not both.', $e->getMessage());
        }
        self::assertSame([], $store->calls);
    }

    #[DataProvider('limits')]
    public function testRecentSearchesLimitIsOneToAThousand(int $limit, bool $allowed): void
    {
        $store = new FakeSearchLogStore();
        if (!$allowed) {
            $this->expectException(InvalidArgument::class);
            $this->expectExceptionMessage(sprintf('The limit must be between 1 and 1000, got %d.', $limit));
        }

        $this->analytics(false, $store)->recentSearches('products', new \DateTimeImmutable(), limit: $limit);

        self::assertCount(1, $store->calls);
    }

    public function testAnonymousOnlyIsPassedToTheStore(): void
    {
        $store = new FakeSearchLogStore();
        $from = new \DateTimeImmutable('-7 days');

        $this->analytics(false, $store)->recentSearches('products', $from, anonymousOnly: true);

        self::assertSame([['recent', 'products', $from, null, 50, null, false, null, true, null]], $store->calls);
    }

    public function testAUserTogetherWithAnonymousOnlyIsRefused(): void
    {
        $store = new FakeSearchLogStore();

        try {
            $this->analytics(false, $store)->recentSearches('products', new \DateTimeImmutable('-7 days'), user: 'ada', anonymousOnly: true);
            self::fail('Expected an InvalidArgument');
        } catch (InvalidArgument $e) {
            self::assertSame('Name a user or pass anonymousOnly: true, not both.', $e->getMessage());
        }
        self::assertSame([], $store->calls);
    }

    /** An empty name is no user: it must not silently match the rows of an empty actor name, nor everything. */
    public function testAnEmptyUserIsRefused(): void
    {
        $store = new FakeSearchLogStore();

        try {
            $this->analytics(false, $store)->recentSearches('products', new \DateTimeImmutable('-7 days'), user: '');
            self::fail('Expected an InvalidArgument');
        } catch (InvalidArgument $e) {
            self::assertSame('Name a user, or pass anonymousOnly: true for the searches without one.', $e->getMessage());
        }
        self::assertSame([], $store->calls);
    }

    public function testAStoreWithDifferentlyNamedParametersStillGetsEveryArgumentInItsPlace(): void
    {
        $store = new class implements SearchLogStore, RecentSearchStore {
            /** @var list<mixed> */
            public array $seen = [];

            public function writeSearchLog(array $records): int
            {
                return 0;
            }

            public function purgeSearchLog(\DateTimeInterface $before): int
            {
                return 0;
            }

            public function topQueries(IndexDefinition $i, \DateTimeInterface $a, ?\DateTimeInterface $b, int $c, int|string|null $d, bool $e): array
            {
                return [];
            }

            public function zeroResultQueries(IndexDefinition $i, \DateTimeInterface $a, ?\DateTimeInterface $b, int $c, int|string|null $d, bool $e): array
            {
                return [];
            }

            public function recentSearches(IndexDefinition $x1, \DateTimeInterface $x2, ?\DateTimeInterface $x3, int $x4, int|string|null $x5, bool $x6, ?string $x7, bool $x8, ?RecordStatus $x9): array
            {
                $this->seen = [$x1->name, $x2, $x3, $x4, $x5, $x6, $x7, $x8, $x9];

                return [];
            }
        };
        $from = new \DateTimeImmutable('-7 days');
        $to = new \DateTimeImmutable('-1 day');

        (new SearchAnalytics($store, new IndexRegistry([Indexes::products()])))->recentSearches('products', $from, $to, 7, 'acme', false, 'ada', false, RecordStatus::Denied);

        self::assertSame(['products', $from, $to, 7, 'acme', false, 'ada', false, RecordStatus::Denied], $store->seen);
    }

    public function testAStoreThatCannotListSearchesIsRefusedClearly(): void
    {
        $store = new class implements SearchLogStore {
            public function writeSearchLog(array $records): int
            {
                return 0;
            }

            public function purgeSearchLog(\DateTimeInterface $before): int
            {
                return 0;
            }

            public function topQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
            {
                return [];
            }

            public function zeroResultQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
            {
                return [];
            }
        };

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage(RecentSearchStore::class);
        (new SearchAnalytics($store, new IndexRegistry([Indexes::products()])))->recentSearches('products', new \DateTimeImmutable('-1 day'));
    }

    public function testThePurgeIsDelegated(): void
    {
        $store = new FakeSearchLogStore();
        $before = new \DateTimeImmutable('-90 days');

        self::assertSame(7, $this->analytics(false, $store)->purge($before));
        self::assertSame([['purge', $before]], $store->calls);
    }

    public function testFuzzphonyHandsOutAnalyticsWhenTheEngineKeepsALog(): void
    {
        $engine = self::createStubForIntersectionOfInterfaces([Engine::class, SearchLogStore::class]);
        assert($engine instanceof Engine);

        self::assertInstanceOf(SearchAnalytics::class, (new Fuzzphony($engine, new IndexRegistry([Indexes::products()])))->analytics());
    }

    public function testFuzzphonyRefusesAnalyticsWhenTheEngineKeepsNoLog(): void
    {
        $engine = self::createStub(Engine::class);
        $engine->method('name')->willReturn('fake');

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('The "fake" engine keeps no search log.');
        (new Fuzzphony($engine, new IndexRegistry([Indexes::products()])))->analytics();
    }
}

/** A store that records what it was asked and returns a fixed list. */
final class FakeSearchLogStore implements SearchLogStore, RecentSearchStore
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];
    /** @var list<QueryStat> */
    public array $stats;
    /** @var list<LoggedSearch> */
    public array $logged;

    public function __construct()
    {
        $this->stats = [new QueryStat('mouse', 4, 2.5, new \DateTimeImmutable('2026-10-01'))];
        $this->logged = [new LoggedSearch(new \DateTimeImmutable('2026-10-01'), 'products', null, 'ada', null, 'mouse', 4, 1.5, false, RecordStatus::Ok)];
    }

    public function writeSearchLog(array $records): int
    {
        return count($records);
    }

    public function purgeSearchLog(\DateTimeInterface $before): int
    {
        $this->calls[] = ['purge', $before];

        return 7;
    }

    public function topQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
    {
        $this->calls[] = ['top', $index->name, $from, $to, $limit, $tenant, $allTenants];

        return $this->stats;
    }

    public function zeroResultQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
    {
        $this->calls[] = ['zero', $index->name, $from, $to, $limit, $tenant, $allTenants];

        return $this->stats;
    }

    public function recentSearches(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants, ?string $user, bool $anonymousOnly, ?RecordStatus $status): array
    {
        $this->calls[] = ['recent', $index->name, $from, $to, $limit, $tenant, $allTenants, $user, $anonymousOnly, $status];

        return $this->logged;
    }
}
