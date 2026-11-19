<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * A worker drain holds the watches row FOR UPDATE for its whole transaction: a re-apply that changes nothing must not wait for it
 * (no ON CONFLICT DO UPDATE on an unchanged row); a real change still waits and gives up after lock_timeout, rolled back whole.
 */
final class SchemaWatchRegisterLockTest extends TestCase
{
    private Connection $connection;
    private Connection $other;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->other = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->apply(Indexes::products('queue'));
    }

    protected function tearDown(): void
    {
        if (isset($this->other)) {
            $this->other->execute('ROLLBACK');
        }
    }

    private function apply(IndexDefinition $index): void
    {
        (new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index])))->schema()->apply($this->connection);
    }

    private function narrower(): IndexDefinition
    {
        return IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['id', 'name'])
            ->field('name', 'A', fuzzy: true)
            ->field('brand', 'B', fuzzy: true)
            ->language('english')
            ->sync('queue')
            ->build();
    }

    private function drainHolds(): void
    {
        $this->other->execute('BEGIN');
        $this->other->execute("SELECT 1 FROM fuzzphony_watches WHERE index_name = 'products' FOR UPDATE");
    }

    public function testANoOpReapplyDoesNotWaitForADrainHoldingTheWatchesRow(): void
    {
        $this->drainHolds();

        $started = microtime(true);
        $this->apply(Indexes::products('queue'));

        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testARealChangeWaitsForTheRowFailsFastAndRollsBackWhole(): void
    {
        $before = $this->connection->fetchValue("SELECT jsonb_agg(columns ORDER BY watch_no)::text FROM fuzzphony_watches WHERE index_name = 'products'");
        $this->drainHolds();

        try {
            $this->apply($this->narrower());
            self::fail('The apply should have given up on the locked row.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('lock', strtolower($e->getMessage() . ($e->getPrevious()?->getMessage() ?? '')));
        }
        $this->other->execute('ROLLBACK');

        self::assertSame($before, $this->connection->fetchValue("SELECT jsonb_agg(columns ORDER BY watch_no)::text FROM fuzzphony_watches WHERE index_name = 'products'"));
        $this->apply($this->narrower());
        self::assertNotSame($before, $this->connection->fetchValue("SELECT jsonb_agg(columns ORDER BY watch_no)::text FROM fuzzphony_watches WHERE index_name = 'products'"));
        self::assertSame(2, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM fuzzphony_watches WHERE index_name = 'products'")));
    }
}
