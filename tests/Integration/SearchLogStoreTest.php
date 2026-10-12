<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Analytics\QueryStat;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\SearchLogStore;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The PostgreSQL search log: written in one batch, purged by age, reported by normalised text. */
final class SearchLogStoreTest extends TestCase
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

    private function store(): PostgresEngine
    {
        return new PostgresEngine($this->connection, searchLog: true);
    }

    private function index(): IndexDefinition
    {
        return Indexes::products();
    }

    private function record(\DateTimeImmutable $at, string $query, int $hits = 1, RecordStatus $status = RecordStatus::Ok, int|string|null $tenant = null, string $index = 'products', ?string $user = null, bool $fuzzy = false, float $tookMs = 1.5): SearchRecord
    {
        return new SearchRecord($at, $index, $tenant, $user, $query, $hits, $tookMs, $fuzzy, $status);
    }

    /**
     * @param list<QueryStat> $stats
     *
     * @return list<string>
     */
    private static function queries(array $stats): array
    {
        return array_map(static fn(QueryStat $s): string => $s->query, $stats);
    }

    public function testTheEngineIsASearchLogStore(): void
    {
        self::assertInstanceOf(SearchLogStore::class, $this->store());
    }

    public function testRecordsAreWrittenInOneBatchAndReportedByNormalisedQuery(): void
    {
        $store = $this->store();
        $now = $this->now;
        $written = $store->writeSearchLog([
            $this->record($now, 'Wireless Mouse', hits: 5),
            $this->record($now->modify('+1 minute'), '  wireless   mouse', hits: 7),
            $this->record($now->modify('+2 minutes'), 'hedphones', hits: 0),
            $this->record($now->modify('+3 minutes'), 'denied one', hits: 0, status: RecordStatus::Denied),
        ]);

        $top = $store->topQueries($this->index(), $now->modify('-1 hour'), null, 10, null, false);

        self::assertSame(4, $written);
        self::assertSame(['wireless mouse', 'hedphones'], self::queries($top));
        self::assertSame([2, 1], array_map(static fn(QueryStat $s): int => $s->searches, $top));
        self::assertEqualsWithDelta(6.0, $top[0]->averageHits, 0.001);
        self::assertEqualsWithDelta(0.0, $top[1]->averageHits, 0.001);
        self::assertSame($now->modify('+1 minute')->getTimestamp(), $top[0]->lastSeen->getTimestamp());
        self::assertSame($now->modify('+2 minutes')->getTimestamp(), $top[1]->lastSeen->getTimestamp());
    }

    public function testEveryColumnOfARecordIsStored(): void
    {
        $this->store()->writeSearchLog([
            $this->record($this->now, 'Wireless Mouse', hits: 3, tenant: 7, user: 'ada', fuzzy: true, tookMs: 12.25),
            $this->record($this->now, 'plain'),
        ]);

        $rows = $this->connection->fetchAll('SELECT * FROM fuzzphony_search_log ORDER BY id');

        self::assertCount(2, $rows);
        self::assertSame('products', $rows[0]['index_name']);
        self::assertSame('7', $rows[0]['tenant']);
        self::assertSame('ada', $rows[0]['actor_user']);
        self::assertSame('Wireless Mouse', $rows[0]['query']);
        self::assertSame('wireless mouse', $rows[0]['query_norm']);
        self::assertSame(3, $rows[0]['hits']);
        self::assertEqualsWithDelta(12.25, Coerce::float($rows[0]['took_ms']), 0.001);
        self::assertTrue($rows[0]['used_fuzzy']);
        self::assertSame('ok', $rows[0]['status']);
        self::assertSame($this->now->getTimestamp(), (new \DateTimeImmutable(Coerce::str($rows[0]['at'])))->getTimestamp());
        self::assertNull($rows[1]['tenant']);
        self::assertNull($rows[1]['actor_user']);
        self::assertFalse($rows[1]['used_fuzzy']);
    }

    public function testSpecialCharactersAreBoundNotInterpolated(): void
    {
        $this->store()->writeSearchLog([$this->record($this->now, "o'brien; DROP TABLE x -- \"q\" :name 100%", tenant: 'acme')]);

        $row = $this->connection->fetchAll('SELECT query, tenant FROM fuzzphony_search_log')[0];

        self::assertSame("o'brien; DROP TABLE x -- \"q\" :name 100%", $row['query']);
        self::assertSame('acme', $row['tenant']);
    }

    /** 7,000 records x 10 columns would be 70,000 bind parameters: over PostgreSQL's limit of 65,535 unless the write is chunked. */
    public function testABatchAboveTheBindParameterLimitIsWrittenInChunks(): void
    {
        $records = [];
        for ($i = 0; $i < 7000; ++$i) {
            $records[] = $this->record($this->now, 'query ' . ($i % 10));
        }

        $written = $this->store()->writeSearchLog($records);

        self::assertSame(7000, $written);
        self::assertSame(7000, $this->connection->fetchValue('SELECT count(*) FROM fuzzphony_search_log'));
    }

    public function testZeroResultQueriesAreOnlyTheOnesThatFoundNothing(): void
    {
        $store = $this->store();
        $now = $this->now;
        $store->writeSearchLog([
            $this->record($now, 'found it', hits: 3),
            $this->record($now->modify('+1 minute'), 'Hedphones', hits: 0),
            $this->record($now->modify('+2 minutes'), 'hedphones ', hits: 0),
            $this->record($now->modify('+3 minutes'), 'mose', hits: 0),
            $this->record($now->modify('+4 minutes'), 'denied one', hits: 0, status: RecordStatus::Denied),
            $this->record($now->modify('+5 minutes'), 'limited one', hits: 0, status: RecordStatus::RateLimited),
        ]);

        $zero = $store->zeroResultQueries($this->index(), $now->modify('-1 hour'), null, 10, null, false);

        self::assertSame(['hedphones', 'mose'], self::queries($zero));
        self::assertSame([2, 1], array_map(static fn(QueryStat $s): int => $s->searches, $zero));
        self::assertEqualsWithDelta(0.0, $zero[0]->averageHits, 0.001);
    }

    public function testTheLimitAndTheOrderOfTiesAreStable(): void
    {
        $store = $this->store();
        $store->writeSearchLog([
            $this->record($this->now, 'b'),
            $this->record($this->now, 'a'),
            $this->record($this->now, 'c'),
            $this->record($this->now, 'c'),
        ]);

        self::assertSame(['c', 'a'], self::queries($store->topQueries($this->index(), $this->now->modify('-1 hour'), null, 2, null, false)));
    }

    public function testTheWindowIsFromInclusiveToExclusive(): void
    {
        $store = $this->store();
        $from = $this->now;
        $to = $this->now->modify('+10 minutes');
        $store->writeSearchLog([
            $this->record($from->modify('-1 second'), 'too early'),
            $this->record($from, 'at from'),
            $this->record($from->modify('+5 minutes'), 'inside'),
            $this->record($to->modify('-1 second'), 'just before to'),
            $this->record($to, 'at to'),
            $this->record($to->modify('+1 minute'), 'too late'),
        ]);

        $inside = $store->topQueries($this->index(), $from, $to, 10, null, false);
        $open = $store->topQueries($this->index(), $from, null, 10, null, false);

        self::assertEqualsCanonicalizing(['at from', 'inside', 'just before to'], self::queries($inside));
        self::assertEqualsCanonicalizing(['at from', 'inside', 'just before to', 'at to', 'too late'], self::queries($open));
    }

    public function testTheWindowAppliesToZeroResultQueriesToo(): void
    {
        $store = $this->store();
        $store->writeSearchLog([
            $this->record($this->now, 'in', hits: 0),
            $this->record($this->now->modify('+1 hour'), 'at to', hits: 0),
        ]);

        self::assertSame(['in'], self::queries($store->zeroResultQueries($this->index(), $this->now, $this->now->modify('+1 hour'), 10, null, false)));
    }

    public function testATenantReportSeesOnlyItsRowsAndAllTenantsSeesAll(): void
    {
        $store = $this->store();
        $now = $this->now;
        $store->writeSearchLog([
            $this->record($now, 'one a', tenant: 1),
            $this->record($now, 'one b', tenant: 1, hits: 0),
            $this->record($now, 'two a', tenant: 2),
            $this->record($now, 'two b', tenant: 2, hits: 0),
            $this->record($now, 'none'),
        ]);
        $from = $now->modify('-1 hour');

        self::assertSame(['one a', 'one b'], self::queries($store->topQueries($this->index(), $from, null, 10, 1, false)));
        self::assertSame(['two a', 'two b'], self::queries($store->topQueries($this->index(), $from, null, 10, '2', false)));
        self::assertEqualsCanonicalizing(['one a', 'one b', 'two a', 'two b', 'none'], self::queries($store->topQueries($this->index(), $from, null, 10, null, true)));
        self::assertEqualsCanonicalizing(['one a', 'one b', 'two a', 'two b', 'none'], self::queries($store->topQueries($this->index(), $from, null, 10, 1, true)), 'allTenants wins over a tenant');
        self::assertSame(['one b'], self::queries($store->zeroResultQueries($this->index(), $from, null, 10, 1, false)));
        self::assertEqualsCanonicalizing(['one b', 'two b'], self::queries($store->zeroResultQueries($this->index(), $from, null, 10, null, true)));
    }

    public function testAStringTenantIsMatchedAsText(): void
    {
        $store = $this->store();
        $store->writeSearchLog([$this->record($this->now, 'mine', tenant: 'acme'), $this->record($this->now, 'theirs', tenant: 'globex')]);

        self::assertSame(['mine'], self::queries($store->topQueries($this->index(), $this->now->modify('-1 hour'), null, 10, 'acme', false)));
    }

    public function testAnotherIndexIsNotReported(): void
    {
        $store = $this->store();
        $store->writeSearchLog([$this->record($this->now, 'mine'), $this->record($this->now, 'other', index: 'articles')]);

        self::assertSame(['mine'], self::queries($store->topQueries($this->index(), $this->now->modify('-1 hour'), null, 10, null, false)));
    }

    public function testPurgeDeletesOnlyOlderRowsAndReturnsTheCount(): void
    {
        $store = $this->store();
        $cut = $this->now;
        $store->writeSearchLog([
            $this->record($cut->modify('-2 days'), 'old a'),
            $this->record($cut->modify('-1 second'), 'old b'),
            $this->record($cut, 'at the cut stays'),
            $this->record($cut->modify('+1 day'), 'new'),
        ]);

        self::assertSame(2, $store->purgeSearchLog($cut));
        self::assertSame(
            ['at the cut stays', 'new'],
            array_column($this->connection->fetchAll('SELECT query FROM fuzzphony_search_log ORDER BY at'), 'query'),
        );
        self::assertSame(0, $store->purgeSearchLog($cut));
    }

    public function testWritingNothingIsANoOp(): void
    {
        $connection = new InterceptingConnection($this->connection);

        self::assertSame(0, (new PostgresEngine($connection, searchLog: true))->writeSearchLog([]));
        self::assertSame([], $connection->statements('INSERT'));
    }

    public function testAMissingTableSurfacesAsAnEngineFailureWithAHint(): void
    {
        $this->connection->execute('DROP TABLE fuzzphony_search_log');

        try {
            $this->store()->writeSearchLog([$this->record($this->now, 'x')]);
            self::fail('Expected an EngineFailure.');
        } catch (EngineFailure $e) {
            self::assertStringContainsString('fuzzphony:schema --apply', $e->getMessage());
        }
    }

    public function testTheTableIsOnlyCreatedWhenTheLogIsOn(): void
    {
        $this->connection->execute('DROP TABLE fuzzphony_search_log');
        (new PostgresEngine($this->connection))->globalSchema()->apply($this->connection);

        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_search_log')"));
    }
}
