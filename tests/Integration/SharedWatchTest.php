<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use PHPUnit\Framework\TestCase;

/** One set of triggers and one change log per watched table, however many indexes watch it; the worker splits a failing batch. */
final class SharedWatchTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_sw_a, fuzzphony_sw_b, fuzzphony_sw_c, fuzzphony_sw_a__vocab, fuzzphony_sw_b__vocab, fuzzphony_sw_c__vocab, fz_bad CASCADE');
    }

    private function index(string $name, string $field, ?string $filter = null): IndexDefinition
    {
        $builder = IndexDefinition::builder($name)->fromTable('fz_product')->field($field, 'A')->sync('queue');

        return ($filter === null ? $builder : $builder->filter($filter, 'int'))->build();
    }

    /** @param list<IndexDefinition> $indexes */
    private function install(array $indexes): Fuzzphony
    {
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry($indexes));
        $fuzzphony->schema()->apply($this->connection);
        foreach ($indexes as $index) {
            $fuzzphony->reindex($index->name);
        }

        return $fuzzphony;
    }

    private function int(string $sql): int
    {
        return Coerce::int($this->connection->fetchValue($sql));
    }

    private function triggers(string $table): int
    {
        return $this->int(sprintf("SELECT count(*) FROM pg_trigger WHERE tgrelid = '%s'::regclass AND NOT tgisinternal", $table));
    }

    private function work(IndexDefinition $index): int
    {
        return (new Worker($this->engine))->runOnce([$index]);
    }

    public function testATableWatchedByThreeIndexesHasOneSetOfTriggersAndOneUpdateWritesOneLogRowPerChangedRow(): void
    {
        $indexes = [$this->index('sw_a', 'name'), $this->index('sw_b', 'name', 'price'), $this->index('sw_c', 'description')];
        $this->install($indexes);

        self::assertSame(4, $this->triggers('fz_product'), 'insert, update, delete and truncate: one set, not three');
        self::assertSame(3, $this->int("SELECT count(*) FROM fuzzphony_watches WHERE tbl = '\"fz_product\"'"));

        // one UPDATE statement: the shared trigger function runs exactly once
        $this->connection->execute("SET track_functions = 'pl'");
        $before = $this->int("SELECT coalesce(sum(calls), 0) FROM pg_stat_user_functions WHERE funcname = 'fuzzphony_watch__fz_product'");
        $this->connection->execute("UPDATE fz_product SET name = name || ' x', description = description || ' y' WHERE id IN (1, 2)");
        $this->connection->execute('SELECT pg_stat_force_next_flush()');
        $calls = $this->int("SELECT coalesce(sum(calls), 0) FROM pg_stat_user_functions WHERE funcname = 'fuzzphony_watch__fz_product'") - $before;
        self::assertSame(1, $calls, 'one trigger firing for the statement, whatever the number of indexes');
        self::assertSame(2, $this->int("SELECT count(*) FROM fuzzphony_watch_log WHERE op = 'U'"), 'the keys of the two changed rows, once');
        self::assertSame(0, $this->int('SELECT count(*) FROM fuzzphony_queue'), 'the queue rows come when the indexes drain');

        foreach ($indexes as $index) {
            self::assertSame(2, $this->work($index), $index->name);
        }
    }

    public function testOneUpdateRefreshesEveryIndexThatWatchesTheTable(): void
    {
        $indexes = [$this->index('sw_a', 'name'), $this->index('sw_b', 'name', 'price'), $this->index('sw_c', 'description')];
        $fuzzphony = $this->install($indexes);

        $this->connection->execute("UPDATE fz_product SET name = 'Zebrastripe mouse', description = 'Zebrastripe description' WHERE id = 1");
        foreach ($indexes as $index) {
            $this->work($index);
        }

        self::assertSame([1], $fuzzphony->in('sw_a')->query('zebrastripe')->get()->ids());
        self::assertSame([1], $fuzzphony->in('sw_b')->query('zebrastripe')->get()->ids());
        self::assertSame([1], $fuzzphony->in('sw_c')->query('zebrastripe')->get()->ids());
    }

    public function testChangesWhileOneIndexsWorkerIsStoppedAreNotLostAndTheLogIsKeptUntilEveryIndexHasReadIt(): void
    {
        [$a, $b] = [$this->index('sw_a', 'name'), $this->index('sw_b', 'name')];
        $fuzzphony = $this->install([$a, $b]);

        $this->connection->execute("UPDATE fz_product SET name = 'Quokkaname one' WHERE id = 1");
        $first = $this->work($a);
        $this->connection->execute("UPDATE fz_product SET name = 'Quokkaname two' WHERE id = 2");
        $second = $this->work($a);
        self::assertSame([1, 1], [$first, $second]);
        self::assertSame(2, $this->int('SELECT count(*) FROM fuzzphony_watch_log'), "index b has not read them: the log keeps them");

        self::assertSame(2, $this->work($b), 'b reads both changes now');
        self::assertEqualsCanonicalizing([1, 2], $fuzzphony->in('sw_b')->query('quokkaname')->get()->ids());
        self::assertSame(0, $this->work($b), 'nothing twice');
        self::assertSame(0, $this->work($a));
        self::assertSame(0, $this->int('SELECT count(*) FROM fuzzphony_watch_log'), 'read by both: pruned');
    }

    public function testAChangeIsNotReadWhileAnOlderWritingTransactionIsStillOpen(): void
    {
        $a = $this->index('sw_a', 'name');
        $this->install([$a]);
        $writer = new \PDO(PostgresTestCase::dsn(), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $writer->beginTransaction();
        $writer->exec("UPDATE fz_product SET name = 'Slowwriter' WHERE id = 3"); // takes the older transaction id, commits last
        $this->connection->execute("UPDATE fz_product SET name = 'Fastwriter' WHERE id = 4");

        self::assertSame(0, $this->work($a), 'the entry of the fast transaction waits: the older one may still commit an entry below it');
        $writer->commit();
        self::assertSame(2, $this->work($a), 'both are read once the older transaction has ended');
    }

    public function testDroppingAnIndexRemovesOnlyItsPartAndTheLastOneTakesTheSharedObjectsAway(): void
    {
        [$a, $b] = [$this->index('sw_a', 'name'), $this->index('sw_b', 'description')];
        $this->install([$a, $b]);
        $this->connection->execute("UPDATE fz_product SET name = 'Keepme', description = 'Keepme too' WHERE id = 1");

        $this->engine->dropSchema($a)->apply($this->connection);

        self::assertSame(4, $this->triggers('fz_product'), 'b still has its trigger');
        self::assertSame(0, $this->int("SELECT count(*) FROM fuzzphony_watches WHERE index_name = 'sw_a'"));
        self::assertSame(1, $this->int("SELECT count(*) FROM fuzzphony_watches WHERE index_name = 'sw_b'"));
        self::assertNotNull($this->connection->fetchValue("SELECT to_regprocedure('fuzzphony_watch__fz_product()')"));
        self::assertSame(1, $this->work($b), 'b keeps reading the log');

        $this->engine->dropSchema($b)->apply($this->connection);

        self::assertSame(0, $this->triggers('fz_product'));
        self::assertNull($this->connection->fetchValue("SELECT to_regprocedure('fuzzphony_watch__fz_product()')"));
        self::assertSame(0, $this->int('SELECT count(*) FROM fuzzphony_watch_log'));
    }

    public function testAnIndexAddedLaterStartsAtTheEndOfTheLogAndFollowsFromThere(): void
    {
        $a = $this->index('sw_a', 'name');
        $this->install([$a]);
        $this->connection->execute("UPDATE fz_product SET name = 'Beforeadd' WHERE id = 1");

        $c = $this->index('sw_c', 'name');
        $fuzzphony = $this->install([$a, $c]); // applies c and runs its first full reindex
        self::assertSame(4, $this->triggers('fz_product'));
        self::assertSame([1], $fuzzphony->in('sw_c')->query('beforeadd')->get()->ids(), 'the first reindex reads the source');
        self::assertSame(0, $this->work($c), "c does not replay what it already reindexed: the entry is older than its start");

        $this->connection->execute("UPDATE fz_product SET name = 'Afteradd' WHERE id = 2");
        self::assertSame(1, $this->work($c));
        self::assertSame([2], $fuzzphony->in('sw_c')->query('afteradd')->get()->ids());
        self::assertSame(2, $this->work($a), 'a reads both entries');
    }

    public function testColumnAwareFilteringStillSkipsAnUpdateNoIndexCaresAbout(): void
    {
        $a = $this->index('sw_a', 'name');
        $b = $this->index('sw_b', 'description');
        $this->install([$a, $b]);

        $this->connection->execute('UPDATE fz_product SET popularity = 9 WHERE id = 1');
        self::assertSame(0, $this->int('SELECT count(*) FROM fuzzphony_watch_log'), 'no index watches that column: nothing is logged');

        $this->connection->execute("UPDATE fz_product SET name = 'Onlyname' WHERE id = 1");
        self::assertSame(1, $this->int('SELECT count(*) FROM fuzzphony_watch_log'));
        self::assertSame(1, $this->work($a));
        self::assertSame(0, $this->work($b), 'b lists description only: the name change is not its business');
    }

    public function testTruncatingTheTableAffectsEveryIndexThatWatchesIt(): void
    {
        $a = $this->index('sw_a', 'name');
        $b = IndexDefinition::builder('sw_b')->fromQuery('SELECT p.id, p.name FROM fz_product p')->watch('fz_product')->field('name', 'A')->sync('queue')->build();
        $fuzzphony = $this->install([$a, $b]);
        $this->connection->execute('TRUNCATE fz_product CASCADE');

        $this->work($a); // drains the TRUNCATE
        self::assertSame(0, $this->int('SELECT count(*) FROM fuzzphony_sw_a'), 'the table source follows the emptied table');
        self::assertSame(1, $this->work($b), "b is a query source: one full rebuild");
        self::assertSame(0, $this->int('SELECT count(*) FROM fuzzphony_sw_b'));
        self::assertSame([], $fuzzphony->in('sw_a')->query('mouse')->get()->ids());
    }

    public function testTheDoctorReportsAnIndexThatIsNotRegisteredAndTheMissingSharedTrigger(): void
    {
        $a = $this->index('sw_a', 'name');
        $fuzzphony = $this->install([$a]);
        $check = static fn(Fuzzphony $f): array => array_values(array_filter($f->inspect('sw_a')->checks, static fn(Check $c): bool => $c->name === 'Shared change log'));

        self::assertSame(CheckStatus::Ok, $check($fuzzphony)[0]->status);
        $this->connection->execute("DELETE FROM fuzzphony_watches WHERE index_name = 'sw_a'");
        self::assertSame(CheckStatus::Error, $check($fuzzphony)[0]->status);
        $fuzzphony->schema()->apply($this->connection);
        self::assertSame(CheckStatus::Ok, $check($fuzzphony)[0]->status, 'the apply repairs it');
    }

    public function testApplyingTwiceChangesNothingAndKeepsThePositionOfTheIndex(): void
    {
        $a = $this->index('sw_a', 'name');
        $fuzzphony = $this->install([$a]);
        $this->connection->execute("UPDATE fz_product SET name = 'Keepposition' WHERE id = 1");
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->schema()->apply($this->connection);

        self::assertSame(4, $this->triggers('fz_product'));
        self::assertSame(1, $this->work($a), 'the entry logged before the second apply is still read');
    }

    public function testAnUpgradeFromPerIndexTriggersSwapsThemWithoutLosingAChange(): void
    {
        $a = $this->index('sw_a', 'name');
        $fuzzphony = $this->install([$a]);
        // what a 0.9.13 install looks like: the per-index statement-level triggers and queue rows, no shared trigger
        $this->connection->execute('DELETE FROM fuzzphony_watches');
        $this->connection->execute('SELECT fuzzphony_watch_install(\'"fz_product"\', \'fuzzphony_watch__fz_product\')');
        self::assertSame(0, $this->triggers('fz_product'));
        $legacy = (new \Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator())->index($a->withTriggerLevel(\Fuzzphony\Core\Definition\TriggerLevel::Statement)->withSync(\Fuzzphony\Core\Definition\SyncMode::Trigger));
        $legacy->apply($this->connection);
        self::assertGreaterThanOrEqual(4, $this->triggers('fz_product'));
        $this->connection->execute("INSERT INTO fuzzphony_queue (index_name, doc_id) VALUES ('sw_a', '3')"); // queued by the old trigger

        $fuzzphony->schema()->apply($this->connection);

        self::assertSame(4, $this->triggers('fz_product'), 'the per-index triggers are gone, the shared ones are there');
        self::assertSame(0, $this->int("SELECT count(*) FROM pg_proc WHERE proname = 'fuzzphony_sync_sw_a__fz_product'"));
        self::assertSame(1, $this->work($a), 'the id queued before the swap is processed');
        $this->connection->execute("UPDATE fz_product SET name = 'Afterswap' WHERE id = 2");
        self::assertSame(1, $this->work($a));
        self::assertSame([2], $fuzzphony->in('sw_a')->query('afterswap')->get()->ids());
    }

    private function badSource(string $name): IndexDefinition
    {
        return IndexDefinition::builder($name)
            ->fromQuery('SELECT b.id, b.label, b.amount::int AS amount FROM fz_bad b')
            ->watch('fz_bad')
            ->field('label', 'A')
            ->filter('amount', 'int')
            ->sync('queue')
            ->build();
    }

    private function bad(): void
    {
        $this->connection->execute('CREATE TABLE fz_bad (id bigint PRIMARY KEY, label text NOT NULL, amount text NOT NULL)');
        $this->connection->execute("INSERT INTO fz_bad SELECT i, 'doc number ' || i, i::text FROM generate_series(1, 20) AS i");
    }

    public function testOneUnreadableDocumentDoesNotHoldBackTheOthersAndIsNamedByTheDoctor(): void
    {
        $this->bad();
        $index = $this->badSource('sw_a');
        $fuzzphony = $this->install([$index]);
        $this->connection->execute("UPDATE fz_bad SET amount = 'secret-value-7' WHERE id = 7");
        $this->connection->execute("UPDATE fz_bad SET label = 'renamed ' || label WHERE id <> 7");

        $processed = $this->work($index);

        self::assertSame(19, $processed, 'the other 19 documents are refreshed');
        self::assertSame(19, $this->int("SELECT count(*) FROM fuzzphony_sw_a WHERE fz IS NOT NULL AND id <> 7"));
        self::assertSame(['7'], array_column($this->connection->fetchAll('SELECT doc_id FROM fuzzphony_queue'), 'doc_id'), 'only the failing id stays queued');
        $row = $this->connection->fetchAll('SELECT attempts, last_error FROM fuzzphony_queue')[0];
        self::assertSame(1, Coerce::int($row['attempts']));
        self::assertSame('PDOException (SQLSTATE 22P02)', $row['last_error'], 'the class and the SQLSTATE, never the driver message');
        self::assertStringNotContainsString('secret-value-7', Coerce::str($row['last_error']));

        $queue = array_values(array_filter($fuzzphony->inspect('sw_a')->checks, static fn(Check $c): bool => $c->name === 'Sync queue'))[0];
        self::assertSame(CheckStatus::Warning, $queue->status);
        self::assertStringContainsString('1 document fail on their own and stay queued: id 7 (PDOException (SQLSTATE 22P02), 1 attempt)', $queue->message);
        self::assertStringNotContainsString('secret-value-7', $queue->message);

        // it waits for its back-off, the next cycle does not even try it
        self::assertSame(0, $this->work($index));
        self::assertSame(1, Coerce::int($this->connection->fetchAll('SELECT attempts FROM fuzzphony_queue')[0]['attempts']));

        // fixing the source clears it at the next try
        $this->connection->execute("UPDATE fz_bad SET amount = '7' WHERE id = 7");
        $this->connection->execute("UPDATE fuzzphony_queue SET failed_at = now() - interval '2 hours'"); // the back-off is over
        self::assertSame(1, $this->work($index), 'the retry succeeds');
        self::assertSame(0, $this->int('SELECT count(*) FROM fuzzphony_queue WHERE attempts > 0'));
        self::assertSame(20, $this->int('SELECT count(*) FROM fuzzphony_sw_a'));
    }

    public function testAnIndexWithABadDocumentDoesNotAffectTheOtherIndex(): void
    {
        $this->bad();
        $first = $this->badSource('sw_a');
        $second = IndexDefinition::builder('sw_b')->fromQuery('SELECT b.id, b.label FROM fz_bad b')->watch('fz_bad')->field('label', 'A')->sync('queue')->build();
        $fuzzphony = $this->install([$first, $second]);
        $this->connection->execute("UPDATE fz_bad SET amount = 'not a number' WHERE id = 3");
        $this->connection->execute("UPDATE fz_bad SET label = 'changed ' || label");

        $worker = new Worker($this->engine);
        self::assertSame(19 + 20, $worker->runOnce([$first, $second]));
        self::assertSame([], $worker->queueFailures(), 'a data failure is not a queue failure: the batch was split');

        self::assertCount(20, $fuzzphony->in('sw_b')->query('changed')->get()->ids());
        self::assertSame(1, $this->int("SELECT count(*) FROM fuzzphony_queue WHERE index_name = 'sw_a' AND attempts > 0"));
        self::assertSame(0, $this->int("SELECT count(*) FROM fuzzphony_queue WHERE index_name = 'sw_b'"));
    }
}
