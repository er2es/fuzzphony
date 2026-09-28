<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\ShadowRebuild;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The exact statements of a rebuild, in order: lock, start, catch-up, swap, release. */
final class ShadowRebuildTest extends TestCase
{
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
    private const array LEFT_OVER_PARAMS = ['shadow' => '"public"."fuzzphony_products__next"', 'changes' => '"public"."fuzzphony_products__changes"'];

    public function testAFullRunTakesTheLockAndStartsTheRebuild(): void
    {
        $connection = new RecordingConnection(static fn(string $sql): mixed => true);
        $generator = new PostgresSchemaGenerator();

        self::assertTrue((new ShadowRebuild($connection, $generator))->begin(Indexes::products(), false));
        self::assertSame([
            [self::LOCK, self::KEY],
            [self::POSSIBLE, self::POSSIBLE_PARAMS],
            [$generator->beginRebuild(Indexes::products()), []],
        ], $connection->log, 'the lock is kept for the rest of the run');
    }

    public function testAnotherRunHoldingTheLockFailsFast(): void
    {
        $connection = new RecordingConnection(static fn(string $sql): mixed => false);

        try {
            (new ShadowRebuild($connection, new PostgresSchemaGenerator()))->begin(Indexes::products(), false);
            self::fail('InvalidArgument expected');
        } catch (InvalidArgument $e) {
            self::assertSame('A rebuild of "products" is already running.', $e->getMessage());
        }
        self::assertSame([[self::LOCK, self::KEY]], $connection->log);
    }

    public function testWithoutTheRightsOrTheFunctionsTheRunGoesInPlaceAndReleasesTheLock(): void
    {
        $connection = new RecordingConnection(static fn(string $sql): mixed => $sql === self::LOCK ? true : null);

        self::assertFalse((new ShadowRebuild($connection, new PostgresSchemaGenerator()))->begin(Indexes::products(), false));
        self::assertSame([
            [self::LOCK, self::KEY],
            [self::POSSIBLE, self::POSSIBLE_PARAMS],
            [self::UNLOCK, self::KEY],
        ], $connection->log);
    }

    public function testAResumedRunContinuesALeftOverRebuildOrGoesInPlace(): void
    {
        $leftOver = new RecordingConnection(static fn(string $sql): mixed => true);
        self::assertTrue((new ShadowRebuild($leftOver, new PostgresSchemaGenerator()))->begin(Indexes::products(), true));
        self::assertSame([[self::LOCK, self::KEY], [self::LEFT_OVER, self::LEFT_OVER_PARAMS]], $leftOver->log, 'nothing is recreated, the lock is kept');

        $none = new RecordingConnection(static fn(string $sql): mixed => $sql === self::LOCK);
        self::assertFalse((new ShadowRebuild($none, new PostgresSchemaGenerator()))->begin(Indexes::products(), true));
        self::assertSame([[self::LOCK, self::KEY], [self::LEFT_OVER, self::LEFT_OVER_PARAMS], [self::UNLOCK, self::KEY]], $none->log);
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
                'Fuzzphony rebuild of "products" failed: the swap could not lock "public"."fuzzphony_products" within 50ms, 6 times (long transactions or autovacuum hold it). The rebuild was kept: call finishRebuild() again, or resume the reindex ("fuzzphony:reindex products --from …").',
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
        self::assertSame([[$generator->discardRebuild(Indexes::products()), []], [self::UNLOCK, self::KEY]], $discard->log);
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
