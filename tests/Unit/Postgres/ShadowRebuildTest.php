<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\ShadowRebuild;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The exact statements of a rebuild, in order: lock, start, catch-up, swap, release. */
final class ShadowRebuildTest extends TestCase
{
    private const string LOCK = 'SELECT pg_try_advisory_lock(hashtext(:key))';
    private const string UNLOCK = 'SELECT pg_advisory_unlock(hashtext(:key))';
    private const array KEY = ['key' => 'fuzzphony:public.products'];
    private const string POSSIBLE = "SELECT has_schema_privilege(:schema, 'CREATE')\n   AND pg_has_role(c.relowner, 'USAGE')\n   AND to_regprocedure(:refresh) IS NOT NULL\n   AND to_regprocedure(:track) IS NOT NULL\nFROM pg_class AS c\nWHERE c.oid = to_regclass(:sidecar)";
    private const array POSSIBLE_PARAMS = [
        'schema' => 'public',
        'refresh' => '"public"."fuzzphony_refresh_products__next"(bigint[])',
        'track' => '"public"."fuzzphony_track_products"()',
        'sidecar' => '"public"."fuzzphony_products"',
    ];
    private const string LEFT_OVER = 'SELECT to_regclass(:shadow) IS NOT NULL AND to_regclass(:changes) IS NOT NULL';
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
        $limited = $catchUp(' WHERE id IN (SELECT id FROM "public"."fuzzphony_products__changes" ORDER BY id LIMIT :limit)');
        self::assertSame([
            ...array_map(static fn(string $sql): array => [$sql, []], $generator->shadowIndexes(Indexes::products())),
            ['BEGIN', []], [$limited, ['limit' => 5_000]], ['COMMIT', []],
            ['BEGIN', []], [$limited, ['limit' => 5_000]], ['COMMIT', []],
            ['BEGIN', []],
            ['LOCK TABLE "public"."fuzzphony_products", "public"."fuzzphony_products__next" IN ACCESS EXCLUSIVE MODE', []],
            [$catchUp(''), []],
            [$generator->swap(Indexes::products()), []],
            ['COMMIT', []],
            [self::UNLOCK, self::KEY],
        ], $connection->log);
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
