<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\RebuildAlreadyRunning;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\ShadowRebuild;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The exact statements of a rebuild, in order: lock, start, catch-up, swap, release. */
final class ShadowRebuildTest extends TestCase
{
    private const array PROBE = [["SELECT set_config('fuzzphony.transaction_probe', 'on', true)", []], ["SELECT current_setting('fuzzphony.transaction_probe', true)", []]];
    private const string LOCK = 'SELECT pg_try_advisory_lock(hashtext(:key))';
    private const string UNLOCK = 'SELECT pg_advisory_unlock(hashtext(:key))';
    private const array KEY = ['key' => 'fuzzphony:public.products'];
    // the reindexing role creates the rebuild and drops the live table (the owner's privileges); the swap
    // hands the rebuild to the owner (SET membership, the owner's CREATE on the schema)
    private const string POSSIBLE = "SELECT has_schema_privilege(:schema, 'CREATE')\n   AND has_schema_privilege(c.relowner, :owner_schema, 'CREATE')\n   AND pg_has_role(c.relowner, 'USAGE')\n   AND pg_has_role(c.relowner, CASE WHEN current_setting('server_version_num')::integer >= 160000 THEN 'SET' ELSE 'MEMBER' END)\n   AND to_regprocedure(:refresh) IS NOT NULL\n   AND to_regprocedure(:track) IS NOT NULL\nFROM pg_class AS c\nWHERE c.oid = to_regclass(:sidecar)";
    private const array POSSIBLE_PARAMS = [
        'schema' => 'public',
        'owner_schema' => 'public',
        'refresh' => '"public"."fuzzphony_refresh_products__next"(bigint[])',
        'track' => '"public"."fuzzphony_track_products"()',
        'sidecar' => '"public"."fuzzphony_products"',
    ];
    private const string LEFT_OVER = 'SELECT to_regclass(:shadow) IS NOT NULL AND to_regclass(:changes) IS NOT NULL';
    private const string SWAP_LOCK = 'LOCK TABLE "public"."fuzzphony_products", "public"."fuzzphony_products__next" IN ACCESS EXCLUSIVE MODE';
    private const string LOCK_TIMEOUT = "SET LOCAL lock_timeout = '3s'";
    private const string XACT_LOCK = 'SELECT pg_try_advisory_xact_lock(hashtext(:key))';
    private const string ANY_LEFT_OVER = 'SELECT to_regclass(:shadow) IS NOT NULL OR to_regclass(:changes) IS NOT NULL OR EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = to_regclass(:sidecar) AND tgname = :trigger)';
    private const array ANY_LEFT_OVER_PARAMS = ['shadow' => '"public"."fuzzphony_products__next"', 'changes' => '"public"."fuzzphony_products__changes"', 'sidecar' => '"public"."fuzzphony_products"', 'trigger' => 'fuzzphony_track_products'];
    private const string CLOCK = 'SELECT clock_timestamp()::text';
    private const string STARTED = '2026-09-28 10:00:00.123456+00';
    private const array LEFT_OVER_PARAMS = ['shadow' => '"public"."fuzzphony_products__next"', 'changes' => '"public"."fuzzphony_products__changes"'];

    public function testAFullRunTakesTheLockAndStartsTheRebuild(): void
    {
        $connection = new RecordingConnection(static fn(string $sql): mixed => true);
        $generator = new PostgresSchemaGenerator();

        self::assertTrue((new ShadowRebuild($connection, $generator))->begin(Indexes::products(), false));
        self::assertSame([
            ...self::PROBE,
            [self::LOCK, self::KEY],
            [self::CLOCK, []],
            [self::POSSIBLE, self::POSSIBLE_PARAMS],
            ['BEGIN', []],
            [self::LOCK_TIMEOUT, []],
            [$generator->beginRebuild(Indexes::products()), []],
            ['COMMIT', []],
        ], $connection->log, 'the lock is kept for the rest of the run');
    }

    public function testAnotherRunHoldingTheLockFailsFast(): void
    {
        $connection = new RecordingConnection(static fn(string $sql): mixed => false);

        try {
            (new ShadowRebuild($connection, new PostgresSchemaGenerator()))->begin(Indexes::products(), false);
            self::fail('InvalidArgument expected');
        } catch (RebuildAlreadyRunning $e) {
            self::assertSame('A rebuild of "products" is already running.', $e->getMessage());
        }
        self::assertSame([...self::PROBE, [self::LOCK, self::KEY]], $connection->log);
    }

    public function testWithoutTheRightsOrTheFunctionsTheRunGoesInPlaceAndReleasesTheLock(): void
    {
        $connection = new RecordingConnection(static fn(string $sql): mixed => $sql === self::LOCK ? true : null);
        $generator = new PostgresSchemaGenerator();

        self::assertFalse((new ShadowRebuild($connection, $generator))->begin(Indexes::products(), false));
        self::assertSame([
            ...self::PROBE,
            [self::LOCK, self::KEY],
            [self::CLOCK, []],
            [self::POSSIBLE, self::POSSIBLE_PARAMS],
            [self::UNLOCK, self::KEY],
        ], $connection->log, 'an in-place run notes its start too');
    }

    public function testInsideACallerTransactionTheRunGoesInPlaceWithoutLocking(): void
    {
        foreach ([false, true] as $resume) {
            $connection = new RecordingConnection(static fn(string $sql): mixed => str_contains($sql, 'current_setting') ? 'on' : true);

            self::assertFalse((new ShadowRebuild($connection, new PostgresSchemaGenerator()))->begin(Indexes::products(), $resume));
            self::assertSame(self::PROBE, $connection->log);
        }
    }

    public function testAResumedRunContinuesALeftOverRebuildOrGoesInPlace(): void
    {
        $leftOver = new RecordingConnection(static fn(string $sql): mixed => true);
        self::assertTrue((new ShadowRebuild($leftOver, new PostgresSchemaGenerator()))->begin(Indexes::products(), true));
        self::assertSame([...self::PROBE, [self::LOCK, self::KEY], [self::LEFT_OVER, self::LEFT_OVER_PARAMS]], $leftOver->log, 'nothing is recreated, the lock is kept');

        $none = new RecordingConnection(static fn(string $sql): mixed => $sql === self::LOCK);
        self::assertFalse((new ShadowRebuild($none, new PostgresSchemaGenerator()))->begin(Indexes::products(), true));
        self::assertSame([...self::PROBE, [self::LOCK, self::KEY], [self::LEFT_OVER, self::LEFT_OVER_PARAMS], [self::UNLOCK, self::KEY]], $none->log);
    }

    public function testDiscardingALeftOverRebuildIsOneTransactionWithATransactionLock(): void
    {
        $leftOver = new RecordingConnection(static fn(string $sql): mixed => true);
        $generator = new PostgresSchemaGenerator();

        self::assertTrue((new ShadowRebuild($leftOver, $generator))->discardLeftover(Indexes::products()));
        self::assertSame([
            [self::CLOCK, []],
            ['BEGIN', []],
            [self::LOCK_TIMEOUT, []],
            [self::XACT_LOCK, self::KEY],
            [self::ANY_LEFT_OVER, self::ANY_LEFT_OVER_PARAMS],
            [$generator->discardRebuild(Indexes::products()), []],
            ['COMMIT', []],
        ], $leftOver->log);

        $running = new RecordingConnection(static fn(string $sql): mixed => $sql !== self::XACT_LOCK);
        self::assertFalse((new ShadowRebuild($running, $generator))->discardLeftover(Indexes::products()));
        self::assertSame([[self::CLOCK, []], ['BEGIN', []], [self::LOCK_TIMEOUT, []], [self::XACT_LOCK, self::KEY], ['COMMIT', []]], $running->log, 'a running rebuild is left alone');

        $nothing = new RecordingConnection(static fn(string $sql): mixed => $sql === self::XACT_LOCK);
        self::assertFalse((new ShadowRebuild($nothing, $generator))->discardLeftover(Indexes::products()));
        self::assertSame([[self::CLOCK, []], ['BEGIN', []], [self::LOCK_TIMEOUT, []], [self::XACT_LOCK, self::KEY], [self::ANY_LEFT_OVER, self::ANY_LEFT_OVER_PARAMS], ['COMMIT', []]], $nothing->log);
    }

    public function testDiscardingALeftOverRebuildGivesUpOnABusyLiveTable(): void
    {
        $generator = new PostgresSchemaGenerator();
        $busy = new RecordingConnection(static function (string $sql) use ($generator): mixed {
            if ($sql === $generator->discardRebuild(Indexes::products())) {
                throw new LockNotAvailable();
            }

            return true;
        });

        try {
            (new ShadowRebuild($busy, $generator, lockTimeout: '50ms'))->discardLeftover(Indexes::products());
            self::fail('EngineFailure expected');
        } catch (EngineFailure $e) {
            self::assertSame('Fuzzphony could not discard the rebuild of "products" a failed run left over: it could not lock "public"."fuzzphony_products" within 50ms (long transactions or autovacuum hold it). Nothing was changed: run it again.', $e->getMessage());
            self::assertInstanceOf(LockNotAvailable::class, $e->getPrevious());
        }
        self::assertSame(["SET LOCAL lock_timeout = '50ms'", []], $busy->log[2]);
    }

    public function testAFullInPlaceRunCompletesTheRequestOnceWhenItEnds(): void
    {
        $generator = new PostgresSchemaGenerator();
        foreach (['after discarding a leftover rebuild' => static fn(ShadowRebuild $r): mixed => $r->discardLeftover(Indexes::products()), 'after falling back to in place' => static fn(ShadowRebuild $r): mixed => $r->begin(Indexes::products(), false)] as $how => $start) {
            $connection = new RecordingConnection(static fn(string $sql): mixed => match ($sql) {
                self::CLOCK => self::STARTED,
                self::LOCK => true,
                default => null,
            });
            $rebuild = new ShadowRebuild($connection, $generator);
            $start($rebuild);
            $connection->log = [];

            $rebuild->complete(Indexes::products());
            $rebuild->complete(Indexes::products());
            self::assertSame([[$generator->completeRebuildRequest(Indexes::products(), self::STARTED), []]], $connection->log, $how);
        }
    }

    public function testARunThatDidNotStartFreshCompletesNothing(): void
    {
        $connection = new RecordingConnection(static fn(string $sql): mixed => $sql === self::CLOCK ? self::STARTED : true);
        $rebuild = new ShadowRebuild($connection, new PostgresSchemaGenerator());

        $rebuild->complete(Indexes::products());
        $rebuild->begin(Indexes::products(), false);
        $rebuild->abort(Indexes::products(), true);
        $rebuild->complete(Indexes::products());
        $rebuild->begin(Indexes::products(), false);
        $rebuild->begin(Indexes::products(), true); // a resumed run: its rebuild may predate the TRUNCATE
        $connection->log = [];
        $rebuild->complete(Indexes::products());

        self::assertSame([], $connection->log);
    }

    public function testTheSwapThatSucceedsCompletesTheRequestInItsTransaction(): void
    {
        $failures = 1;
        $connection = new RecordingConnection(static function (string $sql) use (&$failures): mixed {
            if ($sql === self::SWAP_LOCK && $failures-- > 0) {
                throw new LockNotAvailable();
            }

            return match ($sql) {
                self::CLOCK => self::STARTED,
                self::LOCK => true,
                default => str_starts_with($sql, 'WITH batch') ? 0 : true,
            };
        });
        $generator = new PostgresSchemaGenerator();
        $rebuild = new ShadowRebuild($connection, $generator, pause: static function (int $microseconds): void {});
        $rebuild->begin(Indexes::products(), false);

        $rebuild->finish(Indexes::products());

        $complete = $generator->completeRebuildRequest(Indexes::products(), self::STARTED);
        self::assertSame([[$generator->swap(Indexes::products()), []], [$complete, []], ['COMMIT', []], [self::UNLOCK, self::KEY]], array_slice($connection->log, -4), 'after the retry, in the swap transaction');
        self::assertCount(1, array_keys(array_column($connection->log, 0), $complete, true));
        $connection->log = [];
        $rebuild->complete(Indexes::products());
        self::assertSame([], $connection->log, 'once');
    }

    public function testAFailureWhileStartingReleasesTheLock(): void
    {
        $connection = new RecordingConnection(static function (string $sql): mixed {
            if (str_starts_with($sql, "DO \$fuzzphony\$\nDECLARE")) {
                throw new \RuntimeException('boom');
            }

            return true;
        });

        try {
            (new ShadowRebuild($connection, new PostgresSchemaGenerator()))->begin(Indexes::products(), false);
            self::fail('the failure reaches the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame([[self::UNLOCK, self::KEY]], array_slice($connection->log, -1));
    }

    public function testFinishCatchesUpInBatchesThenRefreshesTheRestAndSwapsUnderTheLock(): void
    {
        $taken = [5_000, 7, 3];
        $connection = new RecordingConnection(static function (string $sql) use (&$taken): mixed {
            return str_starts_with($sql, 'WITH batch') ? array_shift($taken) : null;
        });
        $generator = new PostgresSchemaGenerator();

        (new ShadowRebuild($connection, $generator))->finish(Indexes::products());

        $catchUp = static fn(string $where): string => sprintf(
            "WITH batch AS (\n    DELETE FROM \"public\".\"fuzzphony_products__changes\"%s\n    RETURNING id\n), refreshed AS (\n    SELECT \"public\".\"fuzzphony_refresh_products__next\"(ARRAY(SELECT id FROM batch)) AS written\n)\nSELECT (SELECT count(*) FROM batch) FROM refreshed",
            $where,
        );
        // an id a writer holds (it logged it, not committed yet) waits for the final catch-up under the lock
        $limited = $catchUp(' WHERE id IN (SELECT id FROM "public"."fuzzphony_products__changes" ORDER BY id LIMIT :limit FOR UPDATE SKIP LOCKED)');
        self::assertSame([
            ...array_map(static fn(string $sql): array => [$sql, []], $generator->shadowIndexes(Indexes::products())),
            ['BEGIN', []], [$limited, ['limit' => 5_000]], ['COMMIT', []],
            ['BEGIN', []], [$limited, ['limit' => 5_000]], ['COMMIT', []],
            ['BEGIN', []],
            [self::LOCK_TIMEOUT, []],
            [self::SWAP_LOCK, []],
            [$catchUp(''), []],
            [$generator->swap(Indexes::products()), []],
            ['COMMIT', []],
            [self::UNLOCK, self::KEY],
        ], $connection->log);
    }

    public function testTheCatchUpOutsideTheLockEndsAfterTwentyBatches(): void
    {
        $connection = new RecordingConnection(static fn(string $sql): mixed => str_starts_with($sql, 'WITH batch') ? 5_000 : null);

        (new ShadowRebuild($connection, new PostgresSchemaGenerator()))->finish(Indexes::products());

        $batches = array_filter($connection->log, static fn(array $entry): bool => $entry[1] === ['limit' => 5_000]);
        self::assertCount(20, $batches, 'a write rate above the catch-up rate cannot keep it looping: the rest is refreshed under the lock');
        self::assertContains([self::SWAP_LOCK, []], $connection->log);
    }

    public function testASwapThatCannotTakeTheLockCatchesUpAgainBacksOffAndRetries(): void
    {
        $failures = 2;
        $connection = new RecordingConnection(static function (string $sql) use (&$failures): mixed {
            if ($sql === self::SWAP_LOCK && $failures-- > 0) {
                throw new LockNotAvailable();
            }

            return str_starts_with($sql, 'WITH batch') ? 0 : null;
        });
        $paused = [];

        (new ShadowRebuild($connection, new PostgresSchemaGenerator(), pause: static function (int $microseconds) use (&$paused): void {
            $paused[] = $microseconds;
        }))->finish(Indexes::products());

        $statements = array_values(array_filter(array_column($connection->log, 0), static fn(string $sql): bool => !str_starts_with($sql, 'CREATE INDEX') && !str_starts_with($sql, 'ANALYZE')));
        $batch = static fn(string $sql): string => str_contains($sql, 'LIMIT :limit') ? 'batch' : (str_starts_with($sql, 'WITH batch') ? 'rest' : $sql);
        self::assertSame([
            'BEGIN', 'batch', 'COMMIT',
            'BEGIN', self::LOCK_TIMEOUT, self::SWAP_LOCK,
            'BEGIN', 'batch', 'COMMIT', // one more batch outside the lock
            'BEGIN', self::LOCK_TIMEOUT, self::SWAP_LOCK,
            'BEGIN', 'batch', 'COMMIT',
            'BEGIN', self::LOCK_TIMEOUT, self::SWAP_LOCK, 'rest', (new PostgresSchemaGenerator())->swap(Indexes::products()), 'COMMIT',
            self::UNLOCK,
        ], array_map($batch, $statements));
        self::assertCount(2, $paused);
        self::assertGreaterThanOrEqual(100_000, $paused[0]);
        self::assertLessThanOrEqual(200_000, $paused[0]);
        self::assertGreaterThanOrEqual(200_000, $paused[1], 'doubles');
    }

    public function testASwapThatNeverGetsTheLockKeepsTheRebuildAndSaysHowToResume(): void
    {
        $connection = new RecordingConnection(static function (string $sql): mixed {
            if ($sql === self::SWAP_LOCK) {
                throw new LockNotAvailable();
            }

            return 0;
        });

        try {
            (new ShadowRebuild($connection, new PostgresSchemaGenerator(), lockTimeout: '50ms', pause: static function (int $microseconds): void {}))->finish(Indexes::products());
            self::fail('EngineFailure expected');
        } catch (EngineFailure $e) {
            self::assertSame(
                'Fuzzphony rebuild of "products" failed: the swap could not lock "public"."fuzzphony_products" within 50ms, 6 times (long transactions or autovacuum hold it). The rebuild was kept: run "fuzzphony:reindex products --from=<the last id it printed>" to resume it, or without --from to start over.',
                $e->getMessage(),
            );
            self::assertInstanceOf(LockNotAvailable::class, $e->getPrevious());
        }
        self::assertCount(6, array_keys(array_column($connection->log, 0), self::SWAP_LOCK, true), 'the first attempt and five retries');
        self::assertCount(5 + 1, array_filter($connection->log, static fn(array $entry): bool => $entry[1] === ['limit' => 5_000]), 'the first catch-up and one batch before each retry');
        self::assertContains(["SET LOCAL lock_timeout = '50ms'", []], $connection->log);
        self::assertNotContains([self::UNLOCK, self::KEY], $connection->log, 'the caller releases the lock (abortRebuild(keepShadow: true))');
    }

    /** @return iterable<string, array{\Throwable, bool}> */
    public static function failures(): iterable
    {
        yield 'PDO' => [new LockNotAvailable(), true];
        yield 'wrapped' => [new \RuntimeException('wrapped', 0, new LockNotAvailable()), true];
        yield 'DBAL' => [new class ('lock timeout') extends \RuntimeException {
            public function getSQLState(): string
            {
                return '55P03';
            }
        }, true];
        yield 'another SQLSTATE' => [new class ('deadlock') extends \RuntimeException {
            public function getSQLState(): string
            {
                return '40P01';
            }
        }, false];
        yield 'another PDO error' => [new \PDOException('broken'), false];
    }

    #[DataProvider('failures')]
    public function testOnlyALockTimeoutIsRetried(\Throwable $failure, bool $retried): void
    {
        $failures = 1;
        $connection = new RecordingConnection(static function (string $sql) use (&$failures, $failure): mixed {
            if ($sql === self::SWAP_LOCK && $failures-- > 0) {
                throw $failure;
            }

            return 0;
        });
        $rebuild = new ShadowRebuild($connection, new PostgresSchemaGenerator(), pause: static function (int $microseconds): void {});

        try {
            $rebuild->finish(Indexes::products());
            self::assertTrue($retried, 'finished after a retry');
        } catch (\Throwable $e) {
            self::assertFalse($retried);
            self::assertSame($failure, $e, 'any other failure reaches the caller as it is');
        }
    }

    public function testTheBackOffDoublesWithJitter(): void
    {
        self::assertSame(100_000, ShadowRebuild::backoff(0, 0.0));
        self::assertSame(150_000, ShadowRebuild::backoff(0, 0.5));
        self::assertSame(400_000, ShadowRebuild::backoff(1, 1.0));
        self::assertSame(1_600_000, ShadowRebuild::backoff(4, 0.0));
    }

    public function testAbortDiscardsTheRebuildUnlessItIsKeptForResuming(): void
    {
        $generator = new PostgresSchemaGenerator();
        $keep = new RecordingConnection(static fn(string $sql): mixed => true);
        (new ShadowRebuild($keep, $generator))->abort(Indexes::products(), true);
        self::assertSame([[self::UNLOCK, self::KEY]], $keep->log);

        $discard = new RecordingConnection(static fn(string $sql): mixed => true);
        (new ShadowRebuild($discard, $generator))->abort(Indexes::products(), false);
        self::assertSame([['BEGIN', []], [self::LOCK_TIMEOUT, []], [$generator->discardRebuild(Indexes::products()), []], ['COMMIT', []], [self::UNLOCK, self::KEY]], $discard->log);
    }

    public function testAnAbortThatCannotDiscardTheRebuildStillReleasesTheLock(): void
    {
        $generator = new PostgresSchemaGenerator();
        $boom = new \RuntimeException('boom');
        foreach ([[new LockNotAvailable(), EngineFailure::class], [$boom, null]] as [$failure, $wrapped]) {
            $connection = new RecordingConnection(static function (string $sql) use ($generator, $failure): mixed {
                if ($sql === $generator->discardRebuild(Indexes::products())) {
                    throw $failure;
                }

                return true;
            });

            try {
                (new ShadowRebuild($connection, $generator, lockTimeout: '50ms'))->abort(Indexes::products(), false);
                self::fail('the failure reaches the caller');
            } catch (\Throwable $e) {
                if ($wrapped === null) {
                    self::assertSame($boom, $e, 'any other failure reaches the caller as it is');
                } else {
                    self::assertInstanceOf($wrapped, $e);
                    self::assertSame('Fuzzphony could not discard the rebuild of "products": it could not lock "public"."fuzzphony_products" within 50ms (long transactions or autovacuum hold it). The rebuild is left over (see "fuzzphony:doctor"): the next full reindex replaces it.', $e->getMessage());
                    self::assertSame($failure, $e->getPrevious());
                }
            }
            self::assertSame([[self::UNLOCK, self::KEY]], array_slice($connection->log, -1));
        }
    }

    public function testAStartThatCannotLockTheLiveTableReleasesTheLockAndSaysToRetry(): void
    {
        $generator = new PostgresSchemaGenerator();
        $connection = new RecordingConnection(static function (string $sql) use ($generator): mixed {
            if ($sql === $generator->beginRebuild(Indexes::products())) {
                throw new LockNotAvailable();
            }

            return true;
        });

        try {
            (new ShadowRebuild($connection, $generator, lockTimeout: '50ms'))->begin(Indexes::products(), false);
            self::fail('EngineFailure expected');
        } catch (EngineFailure $e) {
            self::assertSame('Fuzzphony rebuild of "products" did not start: it could not lock "public"."fuzzphony_products" within 50ms (long transactions or autovacuum hold it). Nothing was changed: run it again.', $e->getMessage());
            self::assertInstanceOf(LockNotAvailable::class, $e->getPrevious());
        }
        self::assertSame([["SET LOCAL lock_timeout = '50ms'", []], [$generator->beginRebuild(Indexes::products()), []], [self::UNLOCK, self::KEY]], array_slice($connection->log, -3));
    }

    public function testRefreshWritesTheRebuildTable(): void
    {
        $connection = new RecordingConnection(static fn(string $sql): mixed => '2');
        $rebuild = new ShadowRebuild($connection, new PostgresSchemaGenerator());

        self::assertSame(0, $rebuild->refresh(Indexes::products(), []));
        self::assertSame([], $connection->log, 'no ids, no statement');
        self::assertSame(2, $rebuild->refresh(Indexes::products(), [1, 2]));
        self::assertSame([['SELECT "public"."fuzzphony_refresh_products__next"(CAST(:ids AS bigint[]))', ['ids' => '{1,2}']]], $connection->log);
    }
}

/** @internal What PDO throws for "canceling statement due to lock timeout". */
final class LockNotAvailable extends \PDOException
{
    public function __construct()
    {
        parent::__construct('SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout');
        $this->code = '55P03';
    }
}
