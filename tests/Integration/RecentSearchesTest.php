<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Analytics\LoggedSearch;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Analytics\SearchAnalytics;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** Who searched what: the recent searches read back from the PostgreSQL search log, per user, tenant-safe. */
final class RecentSearchesTest extends TestCase
{
    private Connection $connection;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_search_log CASCADE');
        (new PostgresEngine($this->connection, searchLog: true))->globalSchema()->apply($this->connection);
        $this->now = new \DateTimeImmutable('2026-10-12 10:00:00');
    }

    private function engine(): PostgresEngine
    {
        return new PostgresEngine($this->connection, searchLog: true);
    }

    private function analytics(bool $tenantScoped = false): SearchAnalytics
    {
        return new SearchAnalytics($this->engine(), new IndexRegistry([Indexes::products(tenant: $tenantScoped)]));
    }

    private function record(\DateTimeImmutable $at, string $query, ?string $user = null, int|string|null $tenant = null, RecordStatus $status = RecordStatus::Ok, string $index = 'products', int $hits = 1): SearchRecord
    {
        return new SearchRecord($at, $index, $tenant, $user, $query, $hits, 1.5, false, $status);
    }

    /**
     * @param list<LoggedSearch> $searches
     *
     * @return list<string>
     */
    private static function queries(array $searches): array
    {
        return array_map(static fn(LoggedSearch $s): string => $s->query, $searches);
    }

    public function testTheNewestSearchComesFirstAndTheLaterInsertWinsATie(): void
    {
        $now = $this->now;
        $this->engine()->writeSearchLog([
            $this->record($now->modify('-3 minutes'), 'oldest'),
            $this->record($now, 'tie first inserted'),
            $this->record($now, 'tie second inserted'),
            $this->record($now->modify('-1 minute'), 'middle'),
        ]);

        $found = $this->analytics()->recentSearches('products', $now->modify('-1 hour'));

        self::assertSame(['tie second inserted', 'tie first inserted', 'middle', 'oldest'], self::queries($found));
    }

    public function testEveryFieldOfARowIsReadBack(): void
    {
        $this->engine()->writeSearchLog([new SearchRecord($this->now, 'products', 7, 'ada', 'Wireless  Mouse', 3, 12.25, true, RecordStatus::Ok)]);

        $found = $this->analytics()->recentSearches('products', $this->now->modify('-1 hour'));

        self::assertCount(1, $found);
        self::assertSame($this->now->getTimestamp(), $found[0]->at->getTimestamp());
        self::assertSame('products', $found[0]->index);
        self::assertSame('7', $found[0]->tenant);
        self::assertSame('ada', $found[0]->user);
        self::assertNull($found[0]->ip);
        self::assertSame('Wireless  Mouse', $found[0]->query, 'the stored text, not the normalised one');
        self::assertSame(3, $found[0]->hits);
        self::assertEqualsWithDelta(12.25, $found[0]->tookMs, 0.001);
        self::assertTrue($found[0]->usedFuzzy);
        self::assertSame(RecordStatus::Ok, $found[0]->status);
    }

    public function testNullTenantAndUserAndFalseFuzzyAreReadAsSuch(): void
    {
        $this->engine()->writeSearchLog([$this->record($this->now, 'anon')]);

        $found = $this->analytics()->recentSearches('products', $this->now->modify('-1 hour'));

        self::assertNull($found[0]->tenant);
        self::assertNull($found[0]->user);
        self::assertFalse($found[0]->usedFuzzy);
    }

    public function testTheWindowIsFromInclusiveToExclusive(): void
    {
        $now = $this->now;
        $this->engine()->writeSearchLog([
            $this->record($now->modify('-1 second'), 'before'),
            $this->record($now, 'at from'),
            $this->record($now->modify('+1 hour -1 second'), 'inside'),
            $this->record($now->modify('+1 hour'), 'at to'),
        ]);

        self::assertSame(['inside', 'at from'], self::queries($this->analytics()->recentSearches('products', $now, $now->modify('+1 hour'))));
        self::assertSame(['at to', 'inside', 'at from'], self::queries($this->analytics()->recentSearches('products', $now)), 'no end: everything since');
    }

    public function testTheLimitIsAppliedAfterTheOrder(): void
    {
        $now = $this->now;
        $records = [];
        for ($i = 0; $i < 1001; ++$i) {
            $records[] = $this->record($now->modify(sprintf('+%d seconds', $i)), 'q' . $i);
        }
        $this->engine()->writeSearchLog($records);
        $from = $now->modify('-1 hour');

        self::assertSame(['q1000'], self::queries($this->analytics()->recentSearches('products', $from, limit: 1)));
        self::assertSame(['q1000', 'q999'], self::queries($this->analytics()->recentSearches('products', $from, limit: 2)));
        $all = $this->analytics()->recentSearches('products', $from, limit: 1000);
        self::assertCount(1000, $all);
        self::assertSame('q1', $all[999]->query, 'the oldest one is cut, not the newest');
    }

    public function testTheLimitIsBounded(): void
    {
        foreach ([0, 1001] as $limit) {
            try {
                $this->analytics()->recentSearches('products', $this->now, limit: $limit);
                self::fail('Expected an InvalidArgument');
            } catch (InvalidArgument $e) {
                self::assertSame(sprintf('The limit must be between 1 and 1000, got %d.', $limit), $e->getMessage());
            }
        }
    }

    public function testTheUserFilterIsExactAndCaseSensitive(): void
    {
        $this->engine()->writeSearchLog([
            $this->record($this->now, 'a', 'ada'),
            $this->record($this->now, 'b', 'Ada'),
            $this->record($this->now, 'c', 'ada2'),
            $this->record($this->now, 'd'),
            $this->record($this->now, 'e', ''),
        ]);
        $from = $this->now->modify('-1 hour');

        self::assertSame(['a'], self::queries($this->analytics()->recentSearches('products', $from, user: 'ada')));
        self::assertSame(['b'], self::queries($this->analytics()->recentSearches('products', $from, user: 'Ada')));
        self::assertSame([], $this->analytics()->recentSearches('products', $from, user: 'ad'));
        self::assertSame(['e', 'd', 'c', 'b', 'a'], self::queries($this->analytics()->recentSearches('products', $from)), 'no filter: everybody');
    }

    /** Anonymous means no user at all (NULL): a user whose name is the empty string is somebody, not nobody. */
    public function testTheAnonymousFilterWantsExactlyTheRowsWithoutAUser(): void
    {
        $this->engine()->writeSearchLog([
            $this->record($this->now, 'anon one'),
            $this->record($this->now, 'ada one', 'ada'),
            $this->record($this->now, 'empty name', ''),
            $this->record($this->now, 'anon two'),
        ]);

        $found = $this->analytics()->recentSearches('products', $this->now->modify('-1 hour'), anonymousOnly: true);

        self::assertSame(['anon two', 'anon one'], self::queries($found));
        self::assertNull($found[0]->user);
    }

    public function testAHostileUserNameIsOnlyData(): void
    {
        $this->engine()->writeSearchLog([$this->record($this->now, 'mine', 'ada'), $this->record($this->now, 'bob', "x' OR '1'='1")]);
        $from = $this->now->modify('-1 hour');

        self::assertSame([], $this->analytics()->recentSearches('products', $from, user: "' OR '1'='1"));
        self::assertSame([], $this->analytics()->recentSearches('products', $from, user: "ada'; DROP TABLE fuzzphony_search_log; --"));
        self::assertSame(['bob'], self::queries($this->analytics()->recentSearches('products', $from, user: "x' OR '1'='1")));
        self::assertSame(['bob', 'mine'], self::queries($this->analytics()->recentSearches('products', $from)), 'the table is still there');
    }

    public function testRefusedSearchesAreIncludedAndTheStatusFilterWorks(): void
    {
        $now = $this->now;
        $this->engine()->writeSearchLog([
            $this->record($now->modify('+1 second'), 'ran', 'ada'),
            $this->record($now->modify('+2 seconds'), 'denied', 'ada', status: RecordStatus::Denied, hits: 0),
            $this->record($now->modify('+3 seconds'), 'limited', 'ada', status: RecordStatus::RateLimited, hits: 0),
        ]);
        $analytics = $this->analytics();

        $all = $analytics->recentSearches('products', $now, user: 'ada');
        self::assertSame(['limited', 'denied', 'ran'], self::queries($all));
        self::assertSame([RecordStatus::RateLimited, RecordStatus::Denied, RecordStatus::Ok], array_map(static fn(LoggedSearch $s): RecordStatus => $s->status, $all));
        self::assertSame(['denied'], self::queries($analytics->recentSearches('products', $now, status: RecordStatus::Denied)));
        self::assertSame(['limited'], self::queries($analytics->recentSearches('products', $now, status: RecordStatus::RateLimited)));
        self::assertSame(['ran'], self::queries($analytics->recentSearches('products', $now, status: RecordStatus::Ok)));
    }

    public function testAnotherIndexIsNotListed(): void
    {
        $this->engine()->writeSearchLog([$this->record($this->now, 'mine'), $this->record($this->now, 'other', index: 'articles')]);

        self::assertSame(['mine'], self::queries($this->analytics()->recentSearches('products', $this->now->modify('-1 hour'))));
    }

    /** The same user name in two tenants: a call for one tenant never returns the rows of the other. */
    public function testATenantNeverSeesTheSearchesOfAnotherTenantsUserOfTheSameName(): void
    {
        $this->engine()->writeSearchLog([
            $this->record($this->now, 'a secret plan', 'ada', 'tenant-a'),
            $this->record($this->now, 'b secret plan', 'ada', 'tenant-b'),
            $this->record($this->now, 'b anonymous', null, 'tenant-b'),
            $this->record($this->now, 'b numeric', 'ada', 7),
            $this->record($this->now, 'a numeric', 'ada', 70),
        ]);
        $from = $this->now->modify('-1 hour');
        $analytics = $this->analytics(true);

        self::assertSame(['a secret plan'], self::queries($analytics->recentSearches('products', $from, tenant: 'tenant-a', user: 'ada')));
        self::assertSame(['b anonymous', 'b secret plan'], self::queries($analytics->recentSearches('products', $from, tenant: 'tenant-b')));
        self::assertSame(['b anonymous'], self::queries($analytics->recentSearches('products', $from, tenant: 'tenant-b', anonymousOnly: true)));
        self::assertSame(['b numeric'], self::queries($analytics->recentSearches('products', $from, tenant: 7, user: 'ada')), '7 does not match 70');
        self::assertSame([], $analytics->recentSearches('products', $from, tenant: 'tenant-c', user: 'ada'));
    }

    public function testATenantScopedIndexWithoutATenantIsRefusedAndAllTenantsIsExplicit(): void
    {
        $this->engine()->writeSearchLog([
            $this->record($this->now->modify('+1 second'), 'a one', 'ada', 'tenant-a'),
            $this->record($this->now, 'b one', 'ada', 'tenant-b'),
        ]);
        $from = $this->now->modify('-1 hour');
        $analytics = $this->analytics(true);

        try {
            $analytics->recentSearches('products', $from, user: 'ada');
            self::fail('Expected an InvalidArgument');
        } catch (InvalidArgument $e) {
            self::assertStringContainsString('is tenant-scoped', $e->getMessage());
        }
        $across = $analytics->recentSearches('products', $from, allTenants: true, user: 'ada');
        self::assertSame(['a one', 'b one'], self::queries($across));
        self::assertSame(['tenant-a', 'tenant-b'], array_map(static fn(LoggedSearch $s): ?string => $s->tenant, $across));
    }

    public function testATenantReportOnAnIndexThatIsNotTenantScopedStillFiltersByTenant(): void
    {
        $this->engine()->writeSearchLog([$this->record($this->now, 'one', 'ada', 1), $this->record($this->now, 'two', 'ada', 2)]);

        self::assertSame(['one'], self::queries($this->analytics(false)->recentSearches('products', $this->now->modify('-1 hour'), tenant: 1)));
    }

    public function testAMissingTableIsAnEngineFailureWithAHint(): void
    {
        $this->connection->execute('DROP TABLE fuzzphony_search_log');

        $this->expectException(EngineFailure::class);
        $this->expectExceptionMessage('fuzzphony:schema --apply');
        $this->analytics()->recentSearches('products', $this->now);
    }
}
