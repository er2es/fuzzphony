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
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Core\Sync\ReindexResult;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Sql\Sql;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Fuzzphony::reindex() builds next to the live index: writes during the build, a crash, resume, a second run. */
final class ZeroDowntimeReindexTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->connection->execute('DROP TABLE IF EXISTS fz_note, fz_hidden, fuzzphony_noted, fuzzphony_noted__next, fuzzphony_noted__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_note (id bigint PRIMARY KEY, product_id bigint NOT NULL, note text NOT NULL)');
        $this->connection->execute('CREATE TABLE fz_hidden (product_id bigint PRIMARY KEY)');
        $this->connection->execute("INSERT INTO fz_note VALUES (1, 1, 'fragile'), (2, 3, 'fragile'), (3, 4, 'refurbished')");
        $this->connection->execute('INSERT INTO fz_hidden VALUES (5)');
    }

    protected function tearDown(): void
    {
        $this->connection->fetchValue('SELECT pg_advisory_unlock_all()');
    }

    /** @return iterable<string, array{string}> */
    public static function modes(): iterable
    {
        yield 'queue sync' => ['queue'];
        yield 'trigger sync' => ['trigger'];
    }

    #[DataProvider('modes')]
    public function testWritesDuringTheBuildEndUpInTheSwappedIndex(string $sync): void
    {
        $index = $this->notedIndex($sync);
        $fuzzphony = $this->install($index);
        $batches = 0;

        $result = $fuzzphony->reindex('noted', new ReindexOptions(batchSize: 2, onBatch: function () use (&$batches, $fuzzphony, $index, $sync): void {
            if ($batches++ > 0) {
                return;
            }
            self::assertCount(4, $fuzzphony->in('noted')->get()->ids(), 'searches read the complete live index');
            $this->connection->execute("UPDATE fz_product SET name = 'Silent office mouse' WHERE id = 1");
            $this->connection->execute('DELETE FROM fz_product WHERE id = 4');
            $this->connection->execute("INSERT INTO fz_product VALUES (6, 'Trackball', 'Ergonomic trackball', 1, 5990, true, 3, now())");
            $this->connection->execute("INSERT INTO fz_note VALUES (4, 3, 'discontinued')");
            $this->connection->execute('TRUNCATE fz_hidden'); // product 5 becomes visible
            if ($sync === 'queue') {
                $this->engine->processQueue($index, 100);
            }
        }));

        self::assertTrue($result->swapped);
        self::assertNull($result->pruned);
        (new Worker($this->engine))->runOnce([$index]); // whatever is still queued
        $swapped = $this->documents($index);

        self::assertFalse($fuzzphony->reindex('noted', new ReindexOptions(inPlace: true))->swapped);
        self::assertSame($this->documents($index), $swapped, 'the swapped index equals a fresh in-place rebuild');
        self::assertSame(['1', '2', '3', '5', '6'], array_column($swapped, 'id'));
        self::assertSame(0, $this->engine->queueSize($index));
    }

    public function testACrashKeepsTheLiveIndexAndTheDoctorWarnsUntilTheRunIsResumed(): void
    {
        $index = $this->notedIndex('trigger');
        $fuzzphony = $this->install($index);
        $before = $this->documents($index);

        $last = $this->crash($fuzzphony);

        self::assertSame(2, $last);
        self::assertSame($before, $this->documents($index), 'the live index is untouched');
        $check = $this->check($fuzzphony, 'Rebuild');
        self::assertSame(CheckStatus::Warning, $check->status);
        self::assertSame('A rebuild of "noted" did not finish: "public"."fuzzphony_noted__next" is left over, and every change to the index is logged for it. Resume it with --from (the last id it printed), or run a full reindex, which starts over.', $check->message);
        self::assertSame('bin/console fuzzphony:reindex noted', $check->fix);

        $this->connection->execute("UPDATE fz_product SET name = 'Silent office mouse' WHERE id = 1"); // id 1 is in the rebuild already: logged
        $resumed = $fuzzphony->reindex('noted', new ReindexOptions(batchSize: 2, resumeAfter: $last));

        self::assertTrue($resumed->swapped);
        self::assertSame(2, $resumed->written, 'only the rest: 3 and 4');
        self::assertSame('silent office mouse', $this->connection->fetchValue('SELECT exact FROM fuzzphony_noted WHERE id = 1'), 'caught up from the log');
        self::assertCount(4, $fuzzphony->in('noted')->get()->ids());
        self::assertNull(array_find($fuzzphony->inspect('noted')->checks, static fn(Check $c): bool => $c->name === 'Rebuild'));
    }

    public function testAFullRunAfterACrashStartsOver(): void
    {
        $fuzzphony = $this->install($this->notedIndex('trigger'));
        $this->crash($fuzzphony);

        $result = $fuzzphony->reindex('noted', new ReindexOptions(batchSize: 2));

        self::assertTrue($result->swapped);
        self::assertSame(4, $result->written);
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_noted__next')"));
    }

    public function testAFullInPlaceRunAfterACrashDiscardsTheLeftOverRebuild(): void
    {
        $fuzzphony = $this->install($this->notedIndex('trigger'));
        $this->crash($fuzzphony);

        $result = $fuzzphony->reindex('noted', new ReindexOptions(batchSize: 2, inPlace: true));

        self::assertFalse($result->swapped);
        self::assertSame(4, $result->written);
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_noted__next')"), 'a later --from must not continue it');
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_noted__changes')"));
        self::assertNull(array_find($fuzzphony->inspect('noted')->checks, static fn(Check $c): bool => $c->name === 'Rebuild'), 'nothing left: no Rebuild check');
        self::assertTrue($this->lockIsFree());
        self::assertSame(0, $this->advisoryLocksHeld(), 'no session lock: safe behind a transaction-pooling PgBouncer');
    }

    public function testDiscardingALeftOverRebuildTakesNoSessionLockAndLeavesARunningOneAlone(): void
    {
        $index = $this->notedIndex('trigger');
        $fuzzphony = $this->install($index);
        $this->crash($fuzzphony);
        $other = PostgresTestCase::connect();
        $other->fetchValue("SELECT pg_advisory_lock(hashtext('fuzzphony:public.noted'))");
        try {
            self::assertFalse($this->engine->discardLeftoverRebuild($index), 'a rebuild is running');
            self::assertNotNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_noted__next')"));
        } finally {
            $other->fetchValue('SELECT pg_advisory_unlock_all()');
        }

        self::assertTrue($this->engine->discardLeftoverRebuild($index));
        self::assertSame(0, $this->advisoryLocksHeld());
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_noted__next')"));
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_noted__changes')"));
        self::assertSame(0, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM pg_trigger WHERE tgname = 'fuzzphony_track_noted'")));
        self::assertFalse($this->engine->discardLeftoverRebuild($index), 'nothing is left over');
    }

    public function testADiscardAlsoRemovesWhatIsLeftOfARebuildThatCannotBeResumed(): void
    {
        $index = $this->notedIndex('trigger');
        $fuzzphony = $this->install($index);
        $this->crash($fuzzphony);
        $this->connection->execute('DROP TABLE fuzzphony_noted__next, fuzzphony_noted__changes');

        self::assertTrue($this->engine->discardLeftoverRebuild($index), 'the trigger alone');
        self::assertSame(0, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM pg_trigger WHERE tgname = 'fuzzphony_track_noted'")));
    }

    /** Advisory locks this session holds. */
    private function advisoryLocksHeld(): int
    {
        return Coerce::int($this->connection->fetchValue("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"));
    }

    public function testASecondRunFailsFastWhileOneIsRunning(): void
    {
        $fuzzphony = $this->install($this->notedIndex('trigger'));
        $this->crash($fuzzphony); // leaves a rebuild behind, as a running one would have
        $other = PostgresTestCase::connect();
        $other->fetchValue("SELECT pg_advisory_lock(hashtext('fuzzphony:public.noted'))");
        try {
            try {
                $fuzzphony->reindex('noted');
                self::fail('InvalidArgument expected');
            } catch (InvalidArgument $e) {
                self::assertSame('A rebuild of "noted" is already running.', $e->getMessage());
            }
            $check = $this->check($fuzzphony, 'Rebuild');
            self::assertSame(CheckStatus::Ok, $check->status);
            self::assertSame('a full reindex is building the index next to the live one', $check->message);
        } finally {
            $other->fetchValue('SELECT pg_advisory_unlock_all()');
        }
    }

    public function testAFailedSwapReleasesTheLockKeepsTheRebuildAndTheNextRunInTheSameProcessSucceeds(): void
    {
        $fuzzphony = $this->install($this->notedIndex('trigger'));
        try {
            $fuzzphony->reindex('noted', new ReindexOptions(batchSize: 2, onBatch: function (int $done, int|string $lastId): void {
                if ($lastId !== 4) {
                    return;
                }
                // the catch-up of the logged id 1 violates it: the swap fails after the whole load
                $this->connection->execute('ALTER TABLE fuzzphony_noted__next ADD CONSTRAINT fz_refuse_1 CHECK (id <> 1) NOT VALID');
                $this->connection->execute("UPDATE fz_product SET name = 'Silent office mouse' WHERE id = 1");
            }));
            self::fail('the failed swap reaches the caller');
        } catch (EngineFailure $e) {
            self::assertStringContainsString('fz_refuse_1', $e->getMessage());
        }

        self::assertNotNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_noted__next')"), 'kept for --from');
        self::assertTrue($this->lockIsFree(), 'released: another session may rebuild');

        $result = $fuzzphony->reindex('noted');

        self::assertTrue($result->swapped);
        self::assertSame('silent office mouse', $this->connection->fetchValue('SELECT exact FROM fuzzphony_noted WHERE id = 1'));
        self::assertTrue($this->lockIsFree(), 'a lock taken twice by this session would stay held once');
    }

    public function testInsideACallerTransactionTheRunGoesInPlace(): void
    {
        $fuzzphony = $this->install($this->notedIndex('trigger'));
        $this->connection->execute('DELETE FROM fz_note WHERE id = 3');
        $this->connection->execute('DELETE FROM fz_product WHERE id = 4');

        $result = $this->connection->transactional(static fn(): ReindexResult => $fuzzphony->reindex('noted'));

        self::assertFalse($result->swapped, 'the batches and the swap lock would join the caller transaction');
        self::assertSame(0, $result->pruned, 'the trigger removed 4 already');
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_noted__next')"));
        self::assertTrue($this->lockIsFree());
        self::assertTrue($fuzzphony->reindex('noted')->swapped, 'outside a transaction it swaps');
    }

    public function testTheDoctorAlsoWarnsAboutWhatIsLeftOfARebuildThatCannotBeResumed(): void
    {
        $fuzzphony = $this->install($this->notedIndex('trigger'));
        $this->crash($fuzzphony);
        $this->connection->execute('DROP TABLE fuzzphony_noted__next');

        $check = $this->check($fuzzphony, 'Rebuild');

        self::assertSame(CheckStatus::Warning, $check->status);
        self::assertSame('A rebuild of "noted" did not finish and cannot be resumed: "public"."fuzzphony_noted__changes", trigger "fuzzphony_track_noted" on "public"."fuzzphony_noted" are left over, and every change to the index is logged for it. Run a full reindex, which starts over.', $check->message);
        self::assertSame('bin/console fuzzphony:reindex noted', $check->fix);

        $this->connection->execute('DROP TABLE fuzzphony_noted__changes');
        $check = $this->check($fuzzphony, 'Rebuild');

        self::assertSame(CheckStatus::Error, $check->status, 'the trigger fails every write to the index without its log');
        self::assertSame('A rebuild of "noted" did not finish and cannot be resumed: trigger "fuzzphony_track_noted" on "public"."fuzzphony_noted" is left over, and every write to the index fails on its missing change log. Run a full reindex, which starts over.', $check->message);

        self::assertTrue($fuzzphony->reindex('noted')->swapped);
        self::assertNull(array_find($fuzzphony->inspect('noted')->checks, static fn(Check $c): bool => $c->name === 'Rebuild'));
    }

    public function testALeftOverRebuildTableAloneLogsNothing(): void
    {
        $fuzzphony = $this->install($this->notedIndex('trigger'));
        $this->crash($fuzzphony);
        $this->connection->execute('DROP TABLE fuzzphony_noted__changes CASCADE');
        $this->connection->execute('DROP TRIGGER fuzzphony_track_noted ON fuzzphony_noted');

        $check = $this->check($fuzzphony, 'Rebuild');

        self::assertSame(CheckStatus::Warning, $check->status);
        self::assertSame('A rebuild of "noted" did not finish and cannot be resumed: "public"."fuzzphony_noted__next" is left over. Run a full reindex, which starts over.', $check->message);

        self::assertTrue($fuzzphony->reindex('noted')->swapped);
        self::assertNull(array_find($fuzzphony->inspect('noted')->checks, static fn(Check $c): bool => $c->name === 'Rebuild'));
    }

    public function testTheSchemaCannotBeAppliedWhileARebuildRuns(): void
    {
        $index = $this->notedIndex('trigger');
        $fuzzphony = $this->install($index);
        $other = PostgresTestCase::connect();
        $other->fetchValue("SELECT pg_advisory_lock(hashtext('fuzzphony:public.noted'))");
        $this->connection->execute('DROP FUNCTION fuzzphony_refresh_noted__next(bigint[])');
        try {
            $fuzzphony->schema()->apply($this->connection);
            self::fail('EngineFailure expected');
        } catch (EngineFailure $e) {
            self::assertStringContainsString('A rebuild of "noted" is running (fuzzphony:reindex): apply the schema again when it has finished, a new layout would break it.', $e->getMessage());
        } finally {
            $other->fetchValue('SELECT pg_advisory_unlock_all()');
        }
        self::assertNull($this->connection->fetchValue("SELECT to_regprocedure('fuzzphony_refresh_noted__next(bigint[])')"), 'nothing was applied');

        $fuzzphony->schema()->apply($this->connection);

        self::assertNotNull($this->connection->fetchValue("SELECT to_regprocedure('fuzzphony_refresh_noted__next(bigint[])')"));
        self::assertTrue($this->lockIsFree(), 'the apply holds the lock for its transaction only');
    }

    private function lockIsFree(): bool
    {
        return (bool) PostgresTestCase::connect()->transactional(static fn(Connection $c): mixed => $c->fetchValue("SELECT pg_try_advisory_xact_lock(hashtext('fuzzphony:public.noted'))"));
    }

    /** Fails a full run after its first batch (ids 1 and 2); returns the last id it printed. */
    private function crash(Fuzzphony $fuzzphony): int|string
    {
        $last = null;
        try {
            $fuzzphony->reindex('noted', new ReindexOptions(batchSize: 2, onBatch: static function (int $done, int|string $lastId) use (&$last): void {
                $last = $lastId;

                throw new \RuntimeException('killed');
            }));
            self::fail('the failure reaches the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('killed', $e->getMessage());
        }

        return $last ?? self::fail('no batch ran');
    }

    /** Joined table fz_note (LEFT JOIN) and an anti-join on fz_hidden, both watched. */
    private function notedIndex(string $sync): IndexDefinition
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
            ->build();
    }

    private function install(IndexDefinition $index): Fuzzphony
    {
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex($index->name);

        return $fuzzphony;
    }

    /** @return list<array<string, mixed>> every column but indexed_at, as text */
    private function documents(IndexDefinition $index): array
    {
        $columns = array_diff(array_keys((new PostgresSchemaGenerator())->columns($index)), ['indexed_at']);

        return $this->connection->fetchAll(sprintf(
            'SELECT %s FROM fuzzphony_noted ORDER BY id',
            implode(', ', array_map(static fn(string $c): string => sprintf('%1$s::text AS %1$s', Sql::ident($c)), $columns)),
        ));
    }

    private function check(Fuzzphony $fuzzphony, string $name): Check
    {
        return array_find($fuzzphony->inspect('noted')->checks, static fn(Check $c): bool => $c->name === $name)
            ?? self::fail(sprintf('no "%s" check', $name));
    }
}
