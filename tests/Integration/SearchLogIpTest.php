<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Analytics\LoggedSearch;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Analytics\SearchAnalytics;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\SchemaNotApplied;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The client IP column of the search log: the schema adds it (an install without it fails with SchemaNotApplied). */
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
        $this->connection->execute("INSERT INTO fuzzphony_search_log (at, index_name, actor_user, query, query_norm, hits, took_ms, used_fuzzy, status) VALUES (now(), 'products', 'ada', 'before the upgrade', 'before the upgrade', 1, 1.5, false, 'ok')");
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

    public function testABatchWithoutAnyAddressStoresNullAddresses(): void
    {
        $spy = new InterceptingConnection($this->connection);

        (new PostgresEngine($spy, searchLog: true))->writeSearchLog([$this->record('a'), $this->record('b', user: 'ada')]);

        $inserts = array_values(array_filter($spy->log, static fn(array $call): bool => str_starts_with($call[0], 'INSERT')));
        self::assertCount(1, $inserts);
        self::assertSame(1, substr_count($inserts[0][0], 'actor_ip'));
        self::assertNull($inserts[0][1]['ip0']);
        self::assertNull($inserts[0][1]['ip1']);
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

    public function testAnInstallWithoutTheColumnFailsWithOneClearException(): void
    {
        $this->dropIpColumn();
        $engine = new PostgresEngine($this->connection, searchLog: true);

        foreach ([
            'write' => static fn() => $engine->writeSearchLog([new SearchRecord(new \DateTimeImmutable(), 'products', null, null, 'plain', 1, 1.5, false, RecordStatus::Ok)]),
            'report' => fn() => $this->recent(),
        ] as $what => $call) {
            try {
                $call();
                self::fail($what . ' must not run without the column');
            } catch (SchemaNotApplied $e) {
                self::assertStringContainsString('missing: actor_ip', $e->getMessage(), $what);
                self::assertStringContainsString('fuzzphony:schema --apply', $e->getMessage(), $what);
            }
        }
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
