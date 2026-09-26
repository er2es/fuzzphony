<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** TRUNCATE fires no DELETE trigger; the dedicated TRUNCATE trigger must keep the sidecar right. */
final class TruncateSyncTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->connection->execute('DROP TABLE IF EXISTS fz_note, fz_hidden, fuzzphony_items, fuzzphony_noted, fuzzphony_inherited, inh_child, inh_parent CASCADE');
        // no foreign keys: each table can be truncated on its own
        $this->connection->execute('CREATE TABLE fz_note (id bigint PRIMARY KEY, product_id bigint NOT NULL, note text NOT NULL)');
        $this->connection->execute('CREATE TABLE fz_hidden (product_id bigint PRIMARY KEY)');
        $this->connection->execute("INSERT INTO fz_note VALUES (1, 1, 'fragile'), (2, 3, 'fragile'), (3, 4, 'refurbished')");
        $this->connection->execute('INSERT INTO fz_hidden VALUES (5)');
    }

    /** @return iterable<string, array{string, TriggerLevel}> */
    public static function modes(): iterable
    {
        foreach (['queue', 'trigger'] as $sync) {
            foreach (TriggerLevel::cases() as $level) {
                yield sprintf('%s sync, %s level', $sync, $level->value) => [$sync, $level];
            }
        }
    }

    #[DataProvider('modes')]
    public function testTruncatingTheSourceTableEmptiesTheIndex(string $sync, TriggerLevel $level): void
    {
        $index = IndexDefinition::builder('items')->fromTable('fz_product')->field('name', 'A')->sync($sync)->triggerLevel($level)->build();
        $fuzzphony = $this->install($index);
        $this->connection->execute("INSERT INTO fz_product VALUES (6, 'Trackball', 'Ergonomic trackball', 1, 5990, true, 3, now())");
        $this->connection->execute("INSERT INTO fuzzphony_queue (index_name, doc_id) VALUES ('another_index', '1')");
        self::assertSame($sync === 'queue' ? 1 : 0, $this->engine->queueSize($index));
        self::assertSame($sync === 'queue' ? 5 : 6, $this->rows('fuzzphony_items'));

        $this->connection->execute('TRUNCATE fz_product CASCADE');

        self::assertSame(0, $this->rows('fuzzphony_items'), 'the index follows the emptied source immediately');
        self::assertSame(0, $this->engine->queueSize($index), 'nothing left to process for this index');
        self::assertSame(1, $this->rows('fuzzphony_queue'), "other indexes' queued items are kept");
        self::assertSame([], $fuzzphony->in('items')->query('mouse')->get()->ids());

        // ordinary changes still sync afterwards
        $this->connection->execute("INSERT INTO fz_product VALUES (7, 'Wireless keyboard', 'Quiet keys', 1, 4990, true, 2, now())");
        $this->converge($index);
        self::assertSame([7], $fuzzphony->in('items')->query('keyboard')->get()->ids());
    }

    #[DataProvider('modes')]
    public function testTruncatingAJoinedTableResyncsEveryDocument(string $sync, TriggerLevel $level): void
    {
        $index = $this->notedIndex($sync, $level);
        $fuzzphony = $this->install($index);
        $search = static fn(string $text): array => $fuzzphony->in('noted')->query($text)->thresholds(['fuzzy_mode' => 'never'])->get()->ids();
        self::assertEqualsCanonicalizing([1, 3], $search('fragile'));

        $this->connection->execute('TRUNCATE fz_note');

        if ($sync === 'queue') {
            self::assertEqualsCanonicalizing([1, 2, 3, 4], $this->queued($index), 'every indexed document is queued');
            self::assertEqualsCanonicalizing([1, 3], $search('fragile'), 'nothing changes before the worker runs');
            $this->converge($index);
        }
        self::assertSame([], $search('fragile'));
        self::assertSame([], $search('refurbished'));
        self::assertSame(4, $this->rows('fuzzphony_noted'));
    }

    #[DataProvider('modes')]
    public function testTruncateAlsoPicksUpDocumentsTheSourceOnlyReturnsNow(string $sync, TriggerLevel $level): void
    {
        $index = $this->notedIndex($sync, $level);
        $fuzzphony = $this->install($index);
        self::assertSame(4, $this->rows('fuzzphony_noted'), 'product 5 is hidden');

        $this->connection->execute('TRUNCATE fz_hidden');
        if ($sync === 'queue') {
            self::assertEqualsCanonicalizing([1, 2, 3, 4, 5], $this->queued($index));
        }
        $this->converge($index);

        self::assertSame([5], $fuzzphony->in('noted')->query('torch')->get()->ids());
    }

    #[DataProvider('modes')]
    public function testInsertUpdateAndDeleteStillSync(string $sync, TriggerLevel $level): void
    {
        $index = $this->notedIndex($sync, $level);
        $fuzzphony = $this->install($index);
        $search = static fn(string $text): array => $fuzzphony->in('noted')->query($text)->thresholds(['fuzzy_mode' => 'never'])->get()->ids();

        $this->connection->execute("INSERT INTO fz_note VALUES (4, 2, 'discontinued')");
        $this->connection->execute("UPDATE fz_note SET note = 'refurbished' WHERE id = 1");
        $this->connection->execute('DELETE FROM fz_note WHERE id = 3');
        $this->connection->execute("UPDATE fz_product SET name = 'Ergonomic vertical mouse' WHERE id = 2");
        $this->connection->execute('DELETE FROM fz_product WHERE id = 4');
        if ($sync === 'queue') {
            self::assertEqualsCanonicalizing([1, 2, 4], $this->queued($index), 'only the affected documents are queued');
        }
        $this->converge($index);

        self::assertSame([2], $search('discontinued'));
        self::assertSame([1], $search('refurbished'));
        self::assertSame([3], $search('fragile'));
        self::assertSame([2], $search('ergonomic'));
        self::assertSame(3, $this->rows('fuzzphony_noted'));
    }

    /** TRUNCATE ONLY on an inheritance parent fires its trigger, yet the source (SELECT * FROM parent) still returns the children. */
    #[DataProvider('modes')]
    public function testTruncateOnlyOnAnInheritanceParentKeepsTheChildDocuments(string $sync, TriggerLevel $level): void
    {
        $this->connection->execute('CREATE TABLE inh_parent (id bigint PRIMARY KEY, name text NOT NULL)');
        $this->connection->execute('CREATE TABLE inh_child () INHERITS (inh_parent)');
        $this->connection->execute("INSERT INTO inh_parent VALUES (1, 'parent row')");
        $this->connection->execute("INSERT INTO inh_child VALUES (2, 'child row'), (3, 'child row')");
        $index = IndexDefinition::builder('inherited')->fromTable('inh_parent')->field('name', 'A')->sync($sync)->triggerLevel($level)->build();
        $fuzzphony = $this->install($index);
        self::assertSame(3, $this->rows('fuzzphony_inherited'));

        $this->connection->execute('TRUNCATE ONLY inh_parent');

        self::assertSame(2, $this->rows('inh_parent'), 'the source still returns the child rows');
        if ($sync === 'queue') {
            self::assertEqualsCanonicalizing([1, 2, 3], $this->queued($index), 'resynced, not wiped');
            $this->converge($index);
        }
        self::assertSame(2, $this->rows('fuzzphony_inherited'), 'only the truncated parent row is gone');
        self::assertEqualsCanonicalizing([2, 3], $fuzzphony->in('inherited')->query('child')->get()->ids());
        self::assertSame([], $fuzzphony->in('inherited')->query('parent')->get()->ids());
    }

    /** A running queue worker holds its rows: TRUNCATE skips them instead of waiting (and possibly deadlocking). */
    public function testTruncatingTheSourceTableDoesNotWaitForQueueRowsAWorkerHolds(): void
    {
        $index = IndexDefinition::builder('items')->fromTable('fz_product')->field('name', 'A')->sync('queue')->triggerLevel(TriggerLevel::Statement)->build();
        $this->install($index);
        $this->connection->execute("INSERT INTO fz_product VALUES (6, 'Trackball', 'Ergonomic trackball', 1, 5990, true, 3, now())");
        $this->connection->execute("INSERT INTO fuzzphony_queue (index_name, doc_id) VALUES ('items', '7')");
        self::assertSame(2, $this->engine->queueSize($index));

        $worker = new \PDO(PostgresTestCase::dsn(), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $worker->beginTransaction();
        $worker->exec("SELECT 1 FROM fuzzphony_queue WHERE index_name = 'items' AND doc_id = '6' FOR UPDATE");
        try {
            $this->connection->execute("SET lock_timeout = '1500ms'");
            $this->connection->execute('TRUNCATE fz_product CASCADE');
        } finally {
            $worker->rollBack();
        }

        self::assertSame(0, $this->rows('fuzzphony_items'));
        self::assertSame(1, $this->engine->queueSize($index), 'only the row the worker holds is left; the worker refreshes it');
    }

    /** The own-table shortcut is only for the table source's own watch; a second watched table resyncs. */
    #[DataProvider('modes')]
    public function testTruncatingASecondWatchedTableOfATableSourceResyncsInsteadOfWiping(string $sync, TriggerLevel $level): void
    {
        $index = IndexDefinition::builder('items')
            ->fromTable('fz_product')
            ->field('name', 'A')
            ->watch('fz_product')
            ->watch('fz_note', 'SELECT :id', 'product_id')
            ->sync($sync)
            ->triggerLevel($level)
            ->build();
        $fuzzphony = $this->install($index);
        self::assertSame(5, $this->rows('fuzzphony_items'));

        $this->connection->execute('TRUNCATE fz_note');

        if ($sync === 'queue') {
            self::assertEqualsCanonicalizing([1, 2, 3, 4, 5], $this->queued($index), 'every document is queued, none deleted');
            $this->converge($index);
        }
        self::assertSame(5, $this->rows('fuzzphony_items'), 'the source still has all its rows');
        self::assertNotSame([], $fuzzphony->in('items')->query('mouse')->get()->ids());
    }

    /** Joined table fz_note (LEFT JOIN) and an anti-join on fz_hidden, both watched. */
    private function notedIndex(string $sync, TriggerLevel $level): IndexDefinition
    {
        return IndexDefinition::builder('noted')
            ->fromQuery(<<<'SQL'
                SELECT p.id, p.name, n.note
                FROM fz_product p LEFT JOIN fz_note n ON n.product_id = p.id
                WHERE NOT EXISTS (SELECT 1 FROM fz_hidden h WHERE h.product_id = p.id)
                SQL)
            ->watch('fz_product')
            ->watch('fz_note', 'SELECT :id', 'product_id')
            ->watch('fz_hidden', 'SELECT :id', 'product_id')
            ->field('name', 'A')
            ->field('note', 'B')
            ->sync($sync)
            ->triggerLevel($level)
            ->build();
    }

    private function install(IndexDefinition $index): Fuzzphony
    {
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex($index->name);

        return $fuzzphony;
    }

    private function converge(IndexDefinition $index): void
    {
        (new Worker($this->engine))->runOnce([$index]);
    }

    /** @return list<int> */
    private function queued(IndexDefinition $index): array
    {
        return array_map(
            static fn(array $row): int => Coerce::int($row['doc_id']),
            $this->connection->fetchAll('SELECT doc_id FROM fuzzphony_queue WHERE index_name = :index', ['index' => $index->name]),
        );
    }

    private function rows(string $table): int
    {
        return Coerce::int($this->connection->fetchValue(sprintf('SELECT count(*) FROM %s', $table)));
    }
}
