<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use PHPUnit\Framework\TestCase;

/**
 * A watched table's UPDATE must only refresh documents when a relevant column changed —
 * and, for statement-level triggers, insert-only/delete-only invocations must never error.
 */
final class ColumnAwareFilteringTest extends TestCase
{
    private function connection(): \Fuzzphony\Core\Database\Connection
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());

        return $connection;
    }

    private function apply(\Fuzzphony\Core\Database\Connection $connection, IndexDefinition $index): Fuzzphony
    {
        $engine = new PostgresEngine($connection);
        $fuzzphony = new Fuzzphony($engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex($index->name);

        return $fuzzphony;
    }

    public function testSelfWatchSkipsAnIrrelevantColumnUpdate(): void
    {
        $connection = $this->connection();
        // A table-source index: fields/filters map to name/price only, so relevantColumns()
        // auto-derives ['name', 'price'] for the self-watch — popularity is NOT relevant.
        $index = IndexDefinition::builder('products_direct')
            ->fromTable('fz_product')
            ->field('name', 'A')
            ->filter('price', 'int')
            ->build();
        $this->apply($connection, $index);

        $before = Coerce::int($connection->fetchValue("SELECT count(*) FROM {$index->sidecarTable()}"));
        $connection->execute('UPDATE fz_product SET popularity = 999 WHERE id = 1');

        $queued = Coerce::int($connection->fetchValue(
            'SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n',
            ['n' => 'products_direct'],
        ));
        self::assertSame(0, $queued, 'updating an unrelated column must not enqueue a refresh');
        self::assertSame($before, Coerce::int($connection->fetchValue("SELECT count(*) FROM {$index->sidecarTable()}")));
    }

    public function testSelfWatchEnqueuesOnARelevantColumnUpdate(): void
    {
        $connection = $this->connection();
        $index = IndexDefinition::builder('products_direct')
            ->fromTable('fz_product')
            ->field('name', 'A')
            ->filter('price', 'int')
            ->build();
        $this->apply($connection, $index);

        $connection->execute("UPDATE fz_product SET name = 'Renamed' WHERE id = 1");

        $queued = Coerce::int($connection->fetchValue(
            'SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n AND doc_id = :id',
            ['n' => 'products_direct', 'id' => '1'],
        ));
        self::assertSame(1, $queued, 'updating a mapped field column must enqueue a refresh');
    }

    /** Row-level mirror of testSelfWatchSkipsAnIrrelevantColumnUpdate() (spec requires coverage at both trigger levels). */
    public function testRowLevelSelfWatchSkipsAnIrrelevantColumnUpdate(): void
    {
        $connection = $this->connection();
        $index = IndexDefinition::builder('products_direct')
            ->fromTable('fz_product')
            ->field('name', 'A')
            ->filter('price', 'int')
            ->triggerLevel(TriggerLevel::Row)
            ->build();
        $this->apply($connection, $index);

        $before = Coerce::int($connection->fetchValue("SELECT count(*) FROM {$index->sidecarTable()}"));
        $connection->execute('UPDATE fz_product SET popularity = 999 WHERE id = 1');

        $queued = Coerce::int($connection->fetchValue(
            'SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n',
            ['n' => 'products_direct'],
        ));
        self::assertSame(0, $queued, 'updating an unrelated column at row level must not enqueue a refresh');
        self::assertSame($before, Coerce::int($connection->fetchValue("SELECT count(*) FROM {$index->sidecarTable()}")));
    }

    /** Row-level mirror of testSelfWatchEnqueuesOnARelevantColumnUpdate() (spec requires coverage at both trigger levels). */
    public function testRowLevelSelfWatchEnqueuesOnARelevantColumnUpdate(): void
    {
        $connection = $this->connection();
        $index = IndexDefinition::builder('products_direct')
            ->fromTable('fz_product')
            ->field('name', 'A')
            ->filter('price', 'int')
            ->triggerLevel(TriggerLevel::Row)
            ->build();
        $this->apply($connection, $index);

        $connection->execute("UPDATE fz_product SET name = 'Renamed' WHERE id = 1");

        $queued = Coerce::int($connection->fetchValue(
            'SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n AND doc_id = :id',
            ['n' => 'products_direct', 'id' => '1'],
        ));
        self::assertSame(1, $queued, 'updating a mapped field column at row level must enqueue a refresh');
    }

    /**
     * SyncMode::Trigger refreshes synchronously (no queue), so the only observable proof that
     * column-aware filtering fired is the sidecar row's own indexed_at timestamp: it must not
     * advance for an irrelevant-column update, and must advance for a relevant one.
     */
    public function testTriggerModeSelfWatchIndexedAtOnlyAdvancesOnARelevantColumnUpdate(): void
    {
        $connection = $this->connection();
        $index = IndexDefinition::builder('products_direct')
            ->fromTable('fz_product')
            ->field('name', 'A')
            ->filter('price', 'int')
            ->sync(SyncMode::Trigger)
            ->build();
        $this->apply($connection, $index);

        $initial = Coerce::str($connection->fetchValue("SELECT indexed_at::text FROM {$index->sidecarTable()} WHERE id = 1"));

        $connection->execute('UPDATE fz_product SET popularity = 999 WHERE id = 1');
        $afterIrrelevant = Coerce::str($connection->fetchValue("SELECT indexed_at::text FROM {$index->sidecarTable()} WHERE id = 1"));
        self::assertSame($initial, $afterIrrelevant, 'an irrelevant column update must not advance indexed_at');

        $connection->execute('SELECT pg_sleep(0.01)'); // guarantee a measurable now() difference
        $connection->execute("UPDATE fz_product SET name = 'Renamed' WHERE id = 1");
        $afterRelevant = Coerce::str($connection->fetchValue("SELECT indexed_at::text FROM {$index->sidecarTable()} WHERE id = 1"));
        self::assertNotSame($initial, $afterRelevant, 'a relevant column update must advance indexed_at');
    }

    /**
     * Regression guard: a row-level, table-sourced self-watch must still refresh when only the
     * key column changes, even though the key column isn't itself a field/filter/boost/recency
     * column. Before the fix, the row-level guard's diff-list omitted the key column, so this
     * UPDATE was wrongly suppressed: the old document went stale and the new one was never
     * indexed. The statement-level path doesn't have this bug (its LEFT JOIN already falls back
     * to "treat as changed" when the correlating key has no match on the other side).
     */
    public function testRowLevelSelfWatchEnqueuesBothOldAndNewIdOnAKeyColumnUpdate(): void
    {
        $connection = $this->connection();
        $index = IndexDefinition::builder('products_direct')
            ->fromTable('fz_product')
            ->field('name', 'A')
            ->filter('price', 'int')
            ->triggerLevel(TriggerLevel::Row)
            ->build();
        $this->apply($connection, $index);

        $connection->execute('UPDATE fz_product SET id = 1001 WHERE id = 1');

        $queuedOld = Coerce::int($connection->fetchValue(
            'SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n AND doc_id = :id',
            ['n' => 'products_direct', 'id' => '1'],
        ));
        $queuedNew = Coerce::int($connection->fetchValue(
            'SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n AND doc_id = :id',
            ['n' => 'products_direct', 'id' => '1001'],
        ));
        self::assertSame(1, $queuedOld, 'the stale old-id document must be refreshed away');
        self::assertSame(1, $queuedNew, 'the new-id document must be indexed');
    }

    public function testJoinedWatchWithColumnsSkipsAnIrrelevantColumnUpdate(): void
    {
        $connection = $this->connection();
        $index = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['name'])
            ->field('name', 'A')
            ->field('brand', 'B')
            ->build();
        $this->apply($connection, $index);

        $connection->execute("UPDATE fz_brand SET country = 'FI' WHERE id = 1"); // brand 1 = Logitech, products 1 & 4

        $queued = Coerce::int($connection->fetchValue('SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n', ['n' => 'products']));
        self::assertSame(0, $queued, 'updating an unwatched brand column must not enqueue a refresh');
    }

    public function testJoinedWatchWithColumnsEnqueuesOnARelevantColumnUpdate(): void
    {
        $connection = $this->connection();
        $index = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['name'])
            ->field('name', 'A')
            ->field('brand', 'B')
            ->build();
        $this->apply($connection, $index);

        $connection->execute("UPDATE fz_brand SET name = 'Logitech G' WHERE id = 1"); // fans out to products 1 & 4

        $queued = Coerce::int($connection->fetchValue('SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n', ['n' => 'products']));
        self::assertSame(2, $queued, 'renaming the watched brand column must enqueue both its products');
    }

    /**
     * Regression guard for the exact bug this redesign works around: an INSERT-only or
     * DELETE-only statement-level trigger invocation must not reference the transition table
     * it doesn't have. If statementBodyFiltered() ever regresses to referencing fz_old inside
     * the INSERT branch (or fz_new inside DELETE), this errors instead of just failing an
     * assertion.
     */
    public function testColumnFilteredStatementLevelTriggersDoNotErrorOnInsertOrDelete(): void
    {
        $connection = $this->connection();
        $index = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['name'])
            ->field('name', 'A')
            ->field('brand', 'B')
            ->triggerLevel(TriggerLevel::Statement)
            ->build();
        $this->apply($connection, $index);

        $connection->execute("INSERT INTO fz_brand (id, name, country) VALUES (99, 'New Brand', '')");
        $connection->execute('DELETE FROM fz_brand WHERE id = 99');

        $this->addToAssertionCount(1); // reaching here without a thrown \PDOException is the assertion
    }

    public function testRowLevelTriggersAlsoWorkWithColumnFiltering(): void
    {
        $connection = $this->connection();
        $index = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['name'])
            ->field('name', 'A')
            ->field('brand', 'B')
            ->triggerLevel(TriggerLevel::Row)
            ->build();
        $this->apply($connection, $index);

        $connection->execute("UPDATE fz_brand SET country = 'FI' WHERE id = 1");
        $unrelated = Coerce::int($connection->fetchValue('SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n', ['n' => 'products']));

        $connection->execute("UPDATE fz_brand SET name = 'Logitech G' WHERE id = 1");
        $relevant = Coerce::int($connection->fetchValue('SELECT count(*) FROM fuzzphony_queue WHERE index_name = :n', ['n' => 'products']));

        self::assertSame(0, $unrelated);
        self::assertSame(2, $relevant);
    }
}
