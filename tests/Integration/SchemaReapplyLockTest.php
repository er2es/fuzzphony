<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A re-apply that changes nothing takes no lock a writer holds (it does not queue behind a long writer transaction, which would
 * queue every later write to the watched tables behind the apply); an apply that has to wait gives up after lock_timeout,
 * rolls back and can be run again.
 */
final class SchemaReapplyLockTest extends TestCase
{
    private Connection $connection;
    private Connection $writer;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->writer = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_relock, fuzzphony_relock__vocab CASCADE');
    }

    protected function tearDown(): void
    {
        $this->writer->execute('ROLLBACK');
    }

    private function fuzzphony(string $sync, string $level): Fuzzphony
    {
        $index = IndexDefinition::builder('relock')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id')
            ->field('name', 'A', fuzzy: true)
            ->field('brand', 'B', fuzzy: true)
            ->language('english')
            ->sync($sync)
            ->triggerLevel($level)
            ->build();

        return new Fuzzphony(new PostgresEngine($this->connection, searchLog: true), new IndexRegistry([$index]));
    }

    /** A writer transaction that is still open: ROW EXCLUSIVE on the watched tables and on every table of Fuzzphony. */
    private function openWriter(): void
    {
        $tables = array_map(static fn(array $r): string => Coerce::str($r['t']), $this->writer->fetchAll("SELECT c.oid::regclass::text AS t FROM pg_class AS c WHERE c.relkind IN ('r', 'p') AND (c.relname LIKE 'fuzzphony%' OR c.relname IN ('fz_product', 'fz_brand')) AND c.relnamespace = 'public'::regnamespace"));
        $this->writer->execute('BEGIN');
        $this->writer->execute(sprintf('LOCK TABLE %s IN ROW EXCLUSIVE MODE', implode(', ', $tables)));
    }

    /** @return iterable<string, array{string, string}> */
    public static function modes(): iterable
    {
        yield 'queue, statement level (shared trigger)' => ['queue', 'statement'];
        yield 'queue, row level' => ['queue', 'row'];
        yield 'trigger, statement level' => ['trigger', 'statement'];
        yield 'trigger, row level' => ['trigger', 'row'];
    }

    #[DataProvider('modes')]
    public function testANoOpReapplyDoesNotWaitForAWriter(string $sync, string $level): void
    {
        $fuzzphony = $this->fuzzphony($sync, $level);
        $fuzzphony->schema()->apply($this->connection);
        $this->openWriter();
        $this->connection->execute("SET statement_timeout = '8s'");

        $started = microtime(true);
        $fuzzphony->schema()->apply($this->connection);

        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testABlockedApplyGivesUpAfterTheLockTimeoutRollsBackAndCanBeRetried(): void
    {
        $fuzzphony = $this->fuzzphony('queue', 'statement');
        $fuzzphony->schema()->apply($this->connection);
        $this->connection->execute('DROP INDEX fuzzphony_watch_log_tbl_xid');
        $this->openWriter();
        $this->connection->execute("SET statement_timeout = '20s'");

        $started = microtime(true);
        try {
            $fuzzphony->schema()->apply($this->connection);
            self::fail('the apply must not wait for the writer');
        } catch (EngineFailure $e) {
            self::assertStringContainsString('lock', strtolower($e->getMessage()));
        }
        self::assertLessThan(6.0, microtime(true) - $started);
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_watch_log_tbl_xid')"));
        self::assertSame('0', Coerce::str($this->connection->fetchValue("SELECT current_setting('lock_timeout')")), 'SET LOCAL ends with the transaction');

        $this->writer->execute('ROLLBACK');
        $fuzzphony->schema()->apply($this->connection);
        self::assertNotNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_watch_log_tbl_xid')"));
    }
}
