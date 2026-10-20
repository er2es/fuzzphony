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
use Fuzzphony\Tests\Unit\Core\Observability\RecordingMetricsCollector;
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

    /** An apply that finds the column takes no lock on the table: it does not queue behind (or block) the log's readers and writers. */
    public function testApplyingAgainTakesNoLockOnTheTable(): void
    {
        $plan = (new PostgresEngine($this->connection, searchLog: true))->globalSchema();
        $statement = array_values(array_filter($plan->statements, static fn(\Fuzzphony\Core\Schema\Statement $s): bool => str_contains($s->sql, 'actor_ip')))[0];
        $other = PostgresTestCase::connect();
        $this->connection->execute('BEGIN');
        try {
            $this->connection->execute('LOCK TABLE fuzzphony_search_log IN ACCESS SHARE MODE'); // a reader that is still busy
            $other->execute('BEGIN');
            $other->execute("SET LOCAL lock_timeout = '300ms'");
            $other->execute($statement->sql); // would time out if it asked for ACCESS EXCLUSIVE
            $other->execute('ROLLBACK');
        } finally {
            $this->connection->execute('ROLLBACK');
        }

        self::assertTrue($this->hasColumn());
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

    public function testAnAddressOnAnInstallWithoutTheColumnKeepsTheRowsAndDropsTheAddresses(): void
    {
        $this->dropIpColumn();
        $metrics = new RecordingMetricsCollector();
        $engine = new PostgresEngine($this->connection, searchLog: true, metrics: $metrics);

        $written = $engine->writeSearchLog([$this->record('with ip', '203.0.113.0', 'ada'), $this->record('without ip', null, 'bob'), $this->record('v6', '2001:db8::', 'cy')]);

        self::assertSame(3, $written);
        self::assertSame(['v6', 'without ip', 'with ip'], array_map(static fn(LoggedSearch $s): string => $s->query, $this->recent()), 'every row is kept');
        $dropped = array_values(array_filter($metrics->calls, static fn(array $c): bool => $c[1] === 'fuzzphony.search_log.ip_dropped'));
        self::assertSame([['increment', 'fuzzphony.search_log.ip_dropped', 2.0, []]], $dropped, 'the two addresses that were dropped are counted, once');
    }

    public function testTheRetryHappensOnceForABatchOfSeveralChunks(): void
    {
        $this->dropIpColumn();
        $spy = new InterceptingConnection($this->connection);
        $metrics = new RecordingMetricsCollector();
        $records = [];
        for ($i = 0; $i < 2001; ++$i) {
            $records[] = $this->record('q' . $i, $i === 0 ? '203.0.113.0' : null);
        }

        $written = (new PostgresEngine($spy, searchLog: true, metrics: $metrics))->writeSearchLog($records);

        self::assertSame(2001, $written);
        $inserts = array_values(array_filter($spy->log, static fn(array $c): bool => str_starts_with($c[0], 'INSERT')));
        self::assertCount(4, $inserts, 'the first chunk with the column (failed), then three chunks without it');
        self::assertStringContainsString('actor_ip', $inserts[0][0]);
        foreach (array_slice($inserts, 1) as $insert) {
            self::assertStringNotContainsString('actor_ip', $insert[0]);
        }
        self::assertSame(1, count(array_filter($metrics->calls, static fn(array $c): bool => $c[1] === 'fuzzphony.search_log.ip_dropped')));
    }

    public function testAnotherFailureOfAnAddressBatchIsNotRetried(): void
    {
        $this->connection->execute('DROP TABLE fuzzphony_search_log');
        $spy = new InterceptingConnection($this->connection);

        try {
            (new PostgresEngine($spy, searchLog: true))->writeSearchLog([$this->record('with ip', '203.0.113.0')]);
            self::fail('EngineFailure expected');
        } catch (EngineFailure) {
        }

        self::assertCount(1, array_filter($spy->log, static fn(array $c): bool => str_starts_with($c[0], 'INSERT')), 'a missing table is not a missing column');
    }

    public function testARoleWithoutPrivilegeOnTheAddressColumnGetsNullAddressesNotAnError(): void
    {
        (new PostgresEngine($this->connection, searchLog: true))->writeSearchLog([$this->record('secret place', '203.0.113.0', 'ada')]);
        $role = 'fz_noip_reader_' . getmypid();
        $this->connection->execute('DROP ROLE IF EXISTS ' . $role);
        $this->connection->execute('CREATE ROLE ' . $role);
        try {
            $this->connection->execute('GRANT SELECT (id, at, index_name, tenant, actor_user, query, query_norm, hits, took_ms, used_fuzzy, status) ON fuzzphony_search_log TO ' . $role);
            $this->connection->execute('SET ROLE ' . $role);
            $found = $this->recent();
        } finally {
            $this->connection->execute('RESET ROLE');
            $this->connection->execute('DROP OWNED BY ' . $role);
            $this->connection->execute('DROP ROLE ' . $role);
        }

        self::assertSame(['secret place'], array_map(static fn(LoggedSearch $s): string => $s->query, $found));
        self::assertNull($found[0]->ip);
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
