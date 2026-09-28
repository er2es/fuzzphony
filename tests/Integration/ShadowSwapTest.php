<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\ShadowRebuild;
use Fuzzphony\Engine\Postgres\Sql\Sql;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The rebuild SPI of the PostgreSQL engine: build next to the live table, catch up, swap. */
final class ShadowSwapTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
    }

    protected function tearDown(): void
    {
        // PHPUnit keeps finished test objects (and their connections) alive: never leave a rebuild lock behind
        $this->connection->fetchValue('SELECT pg_advisory_unlock_all()');
    }

    public function testTheSwapKeepsTheLiveNamesAndLeavesNothingBehind(): void
    {
        $index = $this->install('manual');

        $this->rebuild($index);

        $expected = [...array_keys((new PostgresSchemaGenerator())->indexes($index)), 'fuzzphony_products_pkey'];
        sort($expected);
        self::assertSame($expected, array_map(Coerce::str(...), array_column($this->connection->fetchAll(
            "SELECT indexname FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'fuzzphony_products' ORDER BY indexname COLLATE \"C\"",
        ), 'indexname')));
        self::assertSame('fuzzphony_products_pkey', $this->connection->fetchValue("SELECT conname FROM pg_constraint WHERE conrelid = 'fuzzphony_products'::regclass AND contype = 'p'"));
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products__next')"));
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products__changes')"));
        self::assertSame([], $this->connection->fetchAll("SELECT 1 FROM pg_trigger WHERE tgrelid = 'fuzzphony_products'::regclass AND NOT tgisinternal"), 'the change log trigger went with the old table');
        self::assertCount(5, $this->documents($index));
        $other = PostgresTestCase::connect();
        self::assertTrue((bool) $other->fetchValue("SELECT pg_try_advisory_lock(hashtext('fuzzphony:public.products'))"), 'the lock is released');
        $other->fetchValue('SELECT pg_advisory_unlock_all()');
        foreach ($this->engine->inspect($index)->checks as $check) {
            if (str_starts_with($check->name, 'Index ') || str_starts_with($check->name, 'Rebuild')) {
                self::assertSame(CheckStatus::Ok, $check->status, $check->name . ': ' . $check->message);
            }
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function writers(): iterable
    {
        yield 'trigger sync' => ['trigger', false];
        yield 'queue sync, worker during the build' => ['queue', true];
        yield 'queue sync, worker after the swap' => ['queue', false];
    }

    #[DataProvider('writers')]
    public function testChangesMadeDuringTheBuildReachTheSwappedIndex(string $sync, bool $workerDuringTheBuild): void
    {
        $index = $this->install($sync);

        $this->rebuild($index, function () use ($index, $workerDuringTheBuild): void {
            // ids 1 and 2 are in the rebuild already, 3 to 5 are not
            $this->connection->execute("UPDATE fz_product SET name = 'Silent office mouse' WHERE id = 1");
            $this->connection->execute("UPDATE fz_brand SET name = 'Razor' WHERE id = 2");
            $this->connection->execute('DELETE FROM fz_product WHERE id = 4');
            $this->connection->execute("INSERT INTO fz_product VALUES (6, 'Trackball', 'Ergonomic trackball', 1, 5990, true, 3, now())");
            if ($workerDuringTheBuild) {
                self::assertGreaterThan(0, $this->engine->processQueue($index, 100));
            }
        });
        (new Worker($this->engine))->runOnce([$index]); // what is still queued goes into the new live table
        $swapped = $this->documents($index);

        $this->engine->refresh($index, [1, 2, 3, 4, 5, 6]); // a fresh refresh of every id, in place
        self::assertSame($this->documents($index), $swapped, 'the swapped index equals a fresh rebuild');
        self::assertSame(['1', '2', '3', '5', '6'], array_column($swapped, 'id'));
        self::assertSame(0, $this->engine->queueSize($index));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function truncates(): iterable
    {
        yield 'trigger sync, query source' => ['trigger', false];
        yield 'queue sync, query source' => ['queue', false];
        // a truncated table source empties the index (and its queue) instead of resyncing it
        yield 'trigger sync, table source' => ['trigger', true];
        yield 'queue sync, table source' => ['queue', true];
    }

    #[DataProvider('truncates')]
    public function testATruncateDuringTheBuildReachesTheSwappedIndex(string $sync, bool $tableSource): void
    {
        $index = $this->install($sync, $tableSource);

        $this->rebuild($index, function (): void {
            $this->connection->execute('TRUNCATE fz_product');
            $this->connection->execute("INSERT INTO fz_product VALUES (3, 'Wired headphones', 'Studio headphones', 3, 29990, true, 2, now())");
        });

        $this->assertTheSwappedIndexEqualsAFreshRebuild($index, ['3']);
    }

    #[DataProvider('truncates')]
    public function testATruncateAfterTheLoadEmptiesTheSwappedIndex(string $sync, bool $tableSource): void
    {
        $index = $this->install($sync, $tableSource);
        // not yet in the live index in queue sync (queued), but loaded into the rebuild
        $this->connection->execute("INSERT INTO fz_product VALUES (6, 'Trackball', 'Ergonomic trackball', 1, 5990, true, 3, now())");

        $this->rebuild($index, beforeFinish: function (): void {
            self::assertSame(6, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_products__next')));
            $this->connection->execute('TRUNCATE fz_product');
        });

        $this->assertTheSwappedIndexEqualsAFreshRebuild($index, []);
    }

    /** @return iterable<string, array{string}> */
    public static function lateWriters(): iterable
    {
        yield 'queue sync, worker during the build' => ['queue'];
        yield 'manual sync, refresh during the build' => ['manual'];
    }

    /** A document the live index never had: its refresh writes nothing there, the rebuild must still drop it. */
    #[DataProvider('lateWriters')]
    public function testADocumentLoadedIntoTheRebuildOnlyIsCaughtUpToo(string $sync): void
    {
        $index = $this->install($sync);
        $this->connection->execute("INSERT INTO fz_product VALUES (6, 'Trackball', 'Ergonomic trackball', 1, 5990, true, 3, now())");

        $this->rebuild($index, beforeFinish: function () use ($index, $sync): void {
            $this->connection->execute('DELETE FROM fz_product WHERE id = 6');
            if ($sync === 'queue') {
                self::assertSame(1, $this->engine->processQueue($index, 100));
            } else {
                self::assertSame(0, $this->engine->refresh($index, [6]));
            }
        });

        $this->assertTheSwappedIndexEqualsAFreshRebuild($index, ['1', '2', '3', '4', '5']);
    }

    public function testASearchDuringTheBuildSeesTheCompleteLiveIndex(): void
    {
        $index = $this->install('manual');
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));

        $this->rebuild($index, function () use ($fuzzphony): void {
            self::assertSame(2, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_products__next')), 'the rebuild is half done');
            self::assertCount(5, $fuzzphony->in('products')->get()->ids(), 'searches read the complete live index');
        });
    }

    public function testTheLiveRefreshFunctionWritesTheNewTableAfterTheSwap(): void
    {
        $index = $this->install('trigger');
        $this->connection->execute("UPDATE fz_product SET name = 'Wireless mouse 2' WHERE id = 1"); // this session has run (and cached) the live refresh function

        $this->rebuild($index);
        $this->connection->execute("UPDATE fz_product SET name = 'Vertical mouse' WHERE id = 1");

        self::assertSame('vertical mouse', $this->connection->fetchValue('SELECT exact FROM fuzzphony_products WHERE id = 1'));
    }

    public function testTheSwappedTableKeepsTheGrantsAndTheOwner(): void
    {
        $index = $this->install('manual');
        $reader = 'fz_reader_' . getmypid(); // roles are cluster-wide; parallel (Infection) runs must not share one
        $owner = 'fz_owner_' . getmypid();
        $this->connection->execute(sprintf('DROP ROLE IF EXISTS %s, %s', $reader, $owner));
        $this->connection->execute(sprintf('CREATE ROLE %s', $reader));
        $this->connection->execute(sprintf('CREATE ROLE %s', $owner));
        try {
            $this->connection->execute(sprintf('GRANT SELECT ON fuzzphony_products TO %s WITH GRANT OPTION', $reader));
            $this->connection->execute(sprintf('GRANT UPDATE ON fuzzphony_products TO %s', $reader));
            $this->connection->execute(sprintf('GRANT CREATE ON SCHEMA public TO %s', $owner)); // an owner that could have created the table
            $this->connection->execute(sprintf('ALTER TABLE fuzzphony_products OWNER TO %s', $owner));

            $this->rebuild($index);

            $can = fn(string $privilege): bool => (bool) $this->connection->fetchValue('SELECT has_table_privilege(:role, :table, :privilege)', ['role' => $reader, 'table' => 'fuzzphony_products', 'privilege' => $privilege]);
            self::assertTrue($can('SELECT WITH GRANT OPTION'));
            self::assertTrue($can('UPDATE'));
            self::assertFalse($can('UPDATE WITH GRANT OPTION'));
            self::assertFalse($can('INSERT'));
            self::assertSame($owner, $this->connection->fetchValue("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = 'fuzzphony_products'::regclass"));
        } finally {
            $this->connection->execute(sprintf('DROP OWNED BY %s, %s', $reader, $owner));
            $this->connection->execute(sprintf('DROP ROLE %s, %s', $reader, $owner));
        }
    }

    public function testARoleThatWritesTheLiveIndexKeepsWorkingDuringARebuild(): void
    {
        $index = $this->install('queue');
        $writer = 'fz_writer_' . getmypid();
        $this->connection->execute(sprintf('DROP ROLE IF EXISTS %s', $writer));
        $this->connection->execute(sprintf('CREATE ROLE %s', $writer));
        try {
            $this->connection->execute(sprintf('GRANT SELECT ON fz_product, fz_brand TO %s', $writer));
            $this->connection->execute(sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON fuzzphony_products, fuzzphony_queue TO %s', $writer));

            $this->rebuild($index, function () use ($index, $writer): void {
                $this->connection->execute("UPDATE fz_product SET name = 'Silent office mouse' WHERE id = 1");
                $this->connection->execute(sprintf('SET ROLE %s', $writer));
                try {
                    self::assertSame(1, $this->engine->processQueue($index, 100), 'the worker role may write the change log');
                } finally {
                    $this->connection->execute('RESET ROLE');
                }
            });

            self::assertSame('silent office mouse', $this->connection->fetchValue('SELECT exact FROM fuzzphony_products WHERE id = 1'));
            $this->connection->execute(sprintf('SET ROLE %s', $writer));
            try {
                self::assertSame(5, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_products')), 'and still reads the new table');
            } finally {
                $this->connection->execute('RESET ROLE');
            }
        } finally {
            $this->connection->execute(sprintf('DROP OWNED BY %s', $writer));
            $this->connection->execute(sprintf('DROP ROLE %s', $writer));
        }
    }

    public function testTheSwapWaitsForATransactionThatWroteTheLiveIndexAndAResumedRunCatchesItUp(): void
    {
        $index = $this->install('trigger');
        self::assertTrue($this->engine->beginRebuild($index));
        self::assertSame(5, $this->engine->refreshShadow($index, [1, 2, 3, 4, 5]));

        $writer = PostgresTestCase::connect();
        $writer->transactional(function (Connection $writer) use ($index): void {
            // written through the sync trigger, logged, not committed yet
            $writer->execute("UPDATE fz_product SET name = 'Silent office mouse' WHERE id = 1");
            try {
                $this->impatient()->finish($index);
                self::fail('the swap must wait for the writer');
            } catch (EngineFailure $e) {
                self::assertStringContainsString('the swap could not lock "public"."fuzzphony_products" within 100ms, 6 times', $e->getMessage());
                self::assertStringContainsString('lock timeout', $e->getPrevious()?->getMessage() ?? '');
            }
            self::assertNotNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products__next')"), 'the failed swap changed nothing');
            self::assertSame('wireless mouse', $this->connection->fetchValue('SELECT exact FROM fuzzphony_products WHERE id = 1'), 'searches still read the old live table');
        });

        $this->engine->abortRebuild($index, keepShadow: true); // what a failed run does
        self::assertTrue($this->engine->beginRebuild($index, resume: true));
        $this->engine->finishRebuild($index);

        self::assertSame('silent office mouse', $this->connection->fetchValue('SELECT exact FROM fuzzphony_products WHERE id = 1'), 'the committed write was caught up');
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products__next')"));
    }

    /**
     * The lost update: an id logged (and committed) before, changed again by a writer that has not
     * committed yet. The writer's log insert conflicts with the logged row; the conflict must lock
     * it, or a catch-up batch takes the id and refreshes it from the state before the writer.
     */
    public function testACatchUpBatchNeverTakesAnIdWhoseChangeIsNotCommittedYet(): void
    {
        $index = $this->install('trigger');
        self::assertTrue($this->engine->beginRebuild($index));
        self::assertSame(5, $this->engine->refreshShadow($index, [1, 2, 3, 4, 5]));
        $this->connection->execute("UPDATE fz_product SET name = 'Logged mouse' WHERE id = 1");
        self::assertSame(1, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_products__changes WHERE id = 1')));

        PostgresTestCase::connect()->transactional(function (Connection $writer) use ($index): void {
            $writer->execute("UPDATE fz_product SET name = 'Uncommitted mouse' WHERE id = 1");
            try {
                $this->impatient()->finish($index); // the batches run while the writer is open, then the swap times out
                self::fail('the swap must wait for the writer');
            } catch (EngineFailure) {
            }
            self::assertSame(1, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_products__changes WHERE id = 1')), 'still logged');
        });

        $this->engine->finishRebuild($index); // called again once the writer committed

        self::assertSame('uncommitted mouse', $this->connection->fetchValue('SELECT exact FROM fuzzphony_products WHERE id = 1'));
    }

    public function testTheLiveTablesOwnerMustBeAbleToTakeTheRebuild(): void
    {
        $index = $this->install('manual');
        $owner = 'fz_owner_' . getmypid();
        $reindexer = 'fz_reindexer_' . getmypid();
        $this->connection->execute(sprintf('DROP ROLE IF EXISTS %s, %s', $owner, $reindexer));
        $this->connection->execute(sprintf('CREATE ROLE %s', $owner));
        $this->connection->execute(sprintf('CREATE ROLE %s', $reindexer));
        try {
            $this->connection->execute(sprintf('ALTER TABLE fuzzphony_products OWNER TO %s', $owner));
            self::assertFalse($this->engine->beginRebuild($index), 'the owner has no CREATE on the schema: the swap could not hand it the rebuild');

            $this->connection->execute(sprintf('GRANT CREATE ON SCHEMA public TO %s, %s', $owner, $reindexer));
            self::assertTrue($this->engine->beginRebuild($index));
            $this->engine->abortRebuild($index);

            if (Coerce::int($this->connection->fetchValue("SELECT current_setting('server_version_num')::integer")) >= 160000) {
                $this->connection->execute(sprintf('GRANT %s TO %s WITH SET FALSE', $owner, $reindexer));
                $this->connection->execute(sprintf('SET ROLE %s', $reindexer));
                try {
                    self::assertFalse($this->engine->beginRebuild($index), 'a member that cannot SET ROLE to the owner cannot hand it the rebuild');
                } finally {
                    $this->connection->execute('RESET ROLE');
                }
                $this->connection->execute(sprintf('GRANT %s TO %s WITH SET TRUE', $owner, $reindexer));
                $this->connection->execute(sprintf('SET ROLE %s', $reindexer));
                try {
                    self::assertTrue($this->engine->beginRebuild($index));
                    $this->engine->abortRebuild($index);
                } finally {
                    $this->connection->execute('RESET ROLE');
                }
            }
        } finally {
            $this->connection->execute(sprintf('DROP OWNED BY %s, %s', $owner, $reindexer));
            $this->connection->execute(sprintf('DROP ROLE %s, %s', $owner, $reindexer));
        }
    }

    public function testASecondRebuildFailsFastAndTheLockIsFreedAfterwards(): void
    {
        $index = $this->install('manual');
        $other = new PostgresEngine(PostgresTestCase::connect());
        self::assertTrue($this->engine->beginRebuild($index));

        try {
            $other->beginRebuild($index);
            self::fail('InvalidArgument expected');
        } catch (InvalidArgument $e) {
            self::assertSame('A rebuild of "products" is already running.', $e->getMessage());
        }

        $this->engine->abortRebuild($index);
        self::assertTrue($other->beginRebuild($index));
        $other->abortRebuild($index);
    }

    public function testAbortKeepsTheRebuildForResumingOrDiscardsIt(): void
    {
        $index = $this->install('manual');
        self::assertTrue($this->engine->beginRebuild($index));
        self::assertSame(2, $this->engine->refreshShadow($index, [1, 2]));

        $this->engine->abortRebuild($index, keepShadow: true);
        self::assertSame(2, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_products__next')));
        self::assertSame(1, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM pg_trigger WHERE tgname = 'fuzzphony_track_products'")), 'changes are still logged');

        self::assertTrue($this->engine->beginRebuild($index, resume: true), 'a resumed run continues it');
        self::assertSame(2, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_products__next')), 'as it was');

        $this->engine->abortRebuild($index);
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products__next')"));
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products__changes')"));
        self::assertSame(0, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM pg_trigger WHERE tgname = 'fuzzphony_track_products'")));
        self::assertFalse($this->engine->beginRebuild($index, resume: true), 'nothing left to resume: in place');
        self::assertTrue($this->engine->beginRebuild($index), 'and the lock was released');
        $this->engine->abortRebuild($index);
    }

    public function testARoleThatCannotBuildNextToTheLiveIndexGoesInPlace(): void
    {
        $index = $this->install('manual');
        $role = 'fz_noddl_' . getmypid();
        $this->connection->execute(sprintf('DROP ROLE IF EXISTS %s', $role));
        $this->connection->execute(sprintf('CREATE ROLE %s', $role));
        try {
            $this->connection->execute(sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON fuzzphony_products TO %s', $role));
            $this->connection->execute(sprintf('SET ROLE %s', $role));
            try {
                self::assertFalse($this->engine->beginRebuild($index), 'no CREATE on the schema, not the owner');
            } finally {
                $this->connection->execute('RESET ROLE');
            }
        } finally {
            $this->connection->execute(sprintf('DROP OWNED BY %s', $role));
            $this->connection->execute(sprintf('DROP ROLE %s', $role));
        }
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products__next')"));
        self::assertTrue($this->engine->beginRebuild($index), 'the lock was released');
        $this->engine->abortRebuild($index);

        $this->connection->execute('DROP FUNCTION fuzzphony_refresh_products__next(bigint[])'); // upgraded, not applied yet
        self::assertFalse($this->engine->beginRebuild($index));
        $check = array_find($this->engine->inspect($index)->checks, static fn(Check $c): bool => $c->name === 'Rebuild refresh function') ?? self::fail('no check');
        self::assertSame(CheckStatus::Error, $check->status);
        self::assertSame('"public"."fuzzphony_refresh_products__next"(bigint[]) is missing.', $check->message);
        self::assertSame('bin/console fuzzphony:schema --apply', $check->fix);
    }

    /** The engine's rebuild, on this test's session, giving up on the swap lock after 100 ms, without backing off. */
    private function impatient(): ShadowRebuild
    {
        return new ShadowRebuild($this->connection, new PostgresSchemaGenerator(), lockTimeout: '100ms', pause: static function (int $microseconds): void {});
    }

    private function install(string $sync, bool $tableSource = false): IndexDefinition
    {
        $index = $tableSource
            ? IndexDefinition::builder('products')->fromTable('fz_product')->field('name', 'A', fuzzy: true)->field('description', 'D')->filter('price', 'int')->sync($sync)->build()
            : Indexes::products($sync);
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');

        return $index;
    }

    /** @param list<string> $ids */
    private function assertTheSwappedIndexEqualsAFreshRebuild(IndexDefinition $index, array $ids): void
    {
        (new Worker($this->engine))->runOnce([$index]); // what is still queued goes into the new live table
        $swapped = $this->documents($index);

        $this->engine->refresh($index, [1, 2, 3, 4, 5, 6]); // a fresh refresh of every id, in place
        self::assertSame($this->documents($index), $swapped, 'the swapped index equals a fresh rebuild');
        self::assertSame($ids, array_column($swapped, 'id'));
        self::assertSame(0, $this->engine->queueSize($index));
    }

    /**
     * Rebuilds through the SPI in batches of two; $afterFirstBatch runs once, between the first and
     * the second batch, $beforeFinish after the last one.
     */
    private function rebuild(IndexDefinition $index, ?\Closure $afterFirstBatch = null, ?\Closure $beforeFinish = null): void
    {
        self::assertTrue($this->engine->beginRebuild($index));
        $after = null;
        while (($ids = $this->engine->sourceIds($index, $after, 2)) !== []) {
            $this->engine->refreshShadow($index, $ids);
            $after = $ids[array_key_last($ids)];
            if ($afterFirstBatch !== null) {
                $afterFirstBatch();
                $afterFirstBatch = null;
            }
        }
        if ($beforeFinish !== null) {
            $beforeFinish();
        }
        $this->engine->finishRebuild($index);
    }

    /** @return list<array<string, mixed>> every column but indexed_at, as text */
    private function documents(IndexDefinition $index): array
    {
        $columns = array_diff(array_keys((new PostgresSchemaGenerator())->columns($index)), ['indexed_at']);

        return $this->connection->fetchAll(sprintf(
            'SELECT %s FROM fuzzphony_products ORDER BY id',
            implode(', ', array_map(static fn(string $c): string => sprintf('%1$s::text AS %1$s', Sql::ident($c)), $columns)),
        ));
    }
}
