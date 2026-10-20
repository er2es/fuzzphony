<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Analytics\LoggedSearch;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Analytics\SearchAnalytics;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The optional client IP column of the search log: written only when a record has one, read only when the table has it. */
final class SearchLogIpTest extends TestCase
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

    private function record(string $query, ?string $ip = null, ?string $user = null, int|string|null $tenant = null): SearchRecord
    {
        return new SearchRecord($this->now, 'products', $tenant, $user, $query, 1, 1.5, false, RecordStatus::Ok, $ip);
    }

    private function dropIpColumn(): void
    {
        $this->connection->execute('ALTER TABLE fuzzphony_search_log DROP COLUMN actor_ip');
    }

    /** @return list<LoggedSearch> */
    private function recent(?Connection $connection = null, bool $tenantScoped = false, int|string|null $tenant = null): array
    {
        $engine = new PostgresEngine($connection ?? $this->connection, searchLog: true);

        return (new SearchAnalytics($engine, new IndexRegistry([Indexes::products(tenant: $tenantScoped)])))->recentSearches('products', $this->now->modify('-1 hour'), tenant: $tenant, allTenants: $tenantScoped && $tenant === null);
    }

    public function testTheSchemaPlanCreatesTheColumnOnAFreshInstall(): void
    {
        self::assertTrue($this->hasColumn());
    }

    public function testTheSchemaPlanAddsTheColumnToAnExistingTableAndKeepsItsRows(): void
    {
        $engine = new PostgresEngine($this->connection, searchLog: true);
        $this->dropIpColumn();
        $engine->writeSearchLog([$this->record('before the upgrade', user: 'ada')]);
        self::assertFalse($this->hasColumn());

        $engine->globalSchema()->apply($this->connection);
        $engine->globalSchema()->apply($this->connection); // and again: additive and repeatable

        self::assertTrue($this->hasColumn());
        $found = $this->recent();
        self::assertSame(['before the upgrade'], array_map(static fn(LoggedSearch $s): string => $s->query, $found));
        self::assertNull($found[0]->ip);
    }

    public function testAnAddressIsStoredAndReadBackAndNullsStayNull(): void
    {
        (new PostgresEngine($this->connection, searchLog: true))->writeSearchLog([
            $this->record('with ip', '203.0.113.0', 'ada'),
            $this->record('without ip', null, 'ada'),
            $this->record('v6', '2001:db8:abcd::'),
        ]);

        $rows = $this->connection->fetchAll('SELECT query, actor_ip FROM fuzzphony_search_log ORDER BY id');
        self::assertSame([['with ip', '203.0.113.0'], ['without ip', null], ['v6', '2001:db8:abcd::']], array_map(static fn(array $r): array => [$r['query'], $r['actor_ip']], $rows));

        $byQuery = [];
        foreach ($this->recent() as $s) {
            $byQuery[$s->query] = $s->ip;
        }
        self::assertSame(['v6' => '2001:db8:abcd::', 'without ip' => null, 'with ip' => '203.0.113.0'], $byQuery);
    }

    public function testABatchWithoutAnyAddressNeverNamesTheColumn(): void
    {
        $spy = new InterceptingConnection($this->connection);

        (new PostgresEngine($spy, searchLog: true))->writeSearchLog([$this->record('a'), $this->record('b', user: 'ada')]);

        $inserts = array_values(array_filter($spy->log, static fn(array $call): bool => str_starts_with($call[0], 'INSERT')));
        self::assertCount(1, $inserts);
        self::assertStringNotContainsString('actor_ip', $inserts[0][0]);
        self::assertSame([], array_filter(array_keys($inserts[0][1]), static fn(string $k): bool => str_starts_with($k, 'ip')), 'and binds no address');
    }

    public function testABatchWithAnAddressNamesTheColumnOnce(): void
    {
        $spy = new InterceptingConnection($this->connection);

        (new PostgresEngine($spy, searchLog: true))->writeSearchLog([$this->record('a'), $this->record('b', '203.0.113.0')]);

        $inserts = array_values(array_filter($spy->log, static fn(array $call): bool => str_starts_with($call[0], 'INSERT')));
        self::assertCount(1, $inserts);
        self::assertSame(1, substr_count($inserts[0][0], 'actor_ip'));
        self::assertNull($inserts[0][1]['ip0']);
        self::assertSame('203.0.113.0', $inserts[0][1]['ip1']);
    }

    public function testAnInstallWithoutTheColumnKeepsLoggingAndReporting(): void
    {
        $this->dropIpColumn();
        $engine = new PostgresEngine($this->connection, searchLog: true);

        $written = $engine->writeSearchLog([$this->record('plain', user: 'ada'), $this->record('other', user: 'bob')]);

        self::assertSame(2, $written);
        $found = $this->recent();
        self::assertSame(['other', 'plain'], array_map(static fn(LoggedSearch $s): string => $s->query, $found));
        self::assertSame([null, null], array_map(static fn(LoggedSearch $s): ?string => $s->ip, $found));
        $top = $engine->topQueries(Indexes::products(), $this->now->modify('-1 hour'), null, 10, null, false);
        self::assertCount(2, $top, 'the other reports do not care');
    }

    public function testAnAddressOnAnInstallWithoutTheColumnIsAnEngineFailureThatPointsAtTheSchema(): void
    {
        $this->dropIpColumn();

        $this->expectException(EngineFailure::class);
        $this->expectExceptionMessage('fuzzphony:schema --apply');
        (new PostgresEngine($this->connection, searchLog: true))->writeSearchLog([$this->record('with ip', '203.0.113.0')]);
    }

    public function testATenantReportShowsOnlyItsOwnAddresses(): void
    {
        $engine = new PostgresEngine($this->connection, searchLog: true);
        $engine->writeSearchLog([$this->record('tenant one', '203.0.113.0', 'ada', 'one'), $this->record('tenant two', '198.51.100.0', 'ada', 'two')]);

        $one = $this->recent($this->connection, true, 'one');

        self::assertSame(['tenant one'], array_map(static fn(LoggedSearch $s): string => $s->query, $one));
        self::assertSame('203.0.113.0', $one[0]->ip, 'the address of tenant two never shows up in the report of tenant one');
    }

    public function testThePurgeDeletesTheAddressWithTheRow(): void
    {
        $engine = new PostgresEngine($this->connection, searchLog: true);
        $engine->writeSearchLog([$this->record('old', '203.0.113.0', 'ada')]);

        $deleted = $engine->purgeSearchLog($this->now->modify('+1 day'));

        self::assertSame(1, $deleted);
        self::assertSame([], $this->connection->fetchAll('SELECT actor_ip FROM fuzzphony_search_log'));
    }

    private function hasColumn(): bool
    {
        return $this->connection->fetchValue("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'fuzzphony_search_log' AND column_name = 'actor_ip')") === true;
    }
}
