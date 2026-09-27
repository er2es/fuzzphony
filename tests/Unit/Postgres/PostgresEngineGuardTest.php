<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every database call of the engine arrives as EngineFailure, with the driver's exception kept. */
final class PostgresEngineGuardTest extends TestCase
{
    private const string DOCTOR = 'Run "bin/console fuzzphony:doctor" to check the index.';

    /** @return iterable<string, array{string, string, \Closure(string): bool, \Closure(PostgresEngine): mixed}> */
    public static function operations(): iterable
    {
        $always = static fn(string $sql): bool => true;
        yield 'source ids' => ['source ids', 'Run "bin/console fuzzphony:doctor": it checks that the source can be queried.', $always, static fn(PostgresEngine $e): mixed => $e->sourceIds(Indexes::products(), null, 10)];
        yield 'reindex record' => ['reindex record', 'The role running the reindex needs SELECT and UPDATE on "public"."fuzzphony_meta".', $always, static function (PostgresEngine $e): null {
            $e->recordReindex(Indexes::products());
            return null;
        }];
        yield 'queue size' => ['queue size', 'Run "fuzzphony:schema --apply" to create the queue table.', $always, static fn(PostgresEngine $e): mixed => $e->queueSize(Indexes::products())];
        yield 'inspection' => ['inspection', 'Check that this connection can read the catalog and the source.', $always, static fn(PostgresEngine $e): mixed => $e->inspect(Indexes::products())];
        yield 'explain' => ['explain', self::DOCTOR, static fn(string $sql): bool => str_starts_with($sql, 'EXPLAIN'), static fn(PostgresEngine $e): mixed => self::fuzzphony($e)->in('products')->query('mouse')->explain()];
        yield 'highlighting' => ['highlighting', self::DOCTOR, static fn(string $sql): bool => str_contains($sql, 'ts_headline'), static fn(PostgresEngine $e): mixed => self::fuzzphony($e)->in('products')->query('mouse')->highlight('name')->get()];
    }

    /**
     * @param \Closure(string): bool           $failsOn
     * @param \Closure(PostgresEngine): mixed  $call
     */
    #[DataProvider('operations')]
    public function testADriverErrorArrivesAsEngineFailure(string $operation, string $hint, \Closure $failsOn, \Closure $call): void
    {
        $engine = new PostgresEngine(self::connection($failsOn));

        try {
            $call($engine);
            self::fail('EngineFailure expected');
        } catch (EngineFailure $e) {
            self::assertSame(sprintf("Fuzzphony %s failed: boom\nHint: %s", $operation, $hint), $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    public function testTheReindexRecordHintNamesTheConfiguredSchema(): void
    {
        $engine = new PostgresEngine(self::connection(static fn(string $sql): bool => true), schema: 'fuzzphony');

        try {
            $engine->recordReindex(Indexes::products());
            self::fail('EngineFailure expected');
        } catch (EngineFailure $e) {
            self::assertStringEndsWith('Hint: The role running the reindex needs SELECT and UPDATE on "fuzzphony"."fuzzphony_meta".', $e->getMessage());
        }
    }

    private static function fuzzphony(PostgresEngine $engine): Fuzzphony
    {
        return new Fuzzphony($engine, new IndexRegistry([Indexes::products()]));
    }

    /**
     * A connection that throws \PDOException('boom') for every statement $failsOn selects; a
     * ranked search statement returns one hit, everything else nothing.
     *
     * @param \Closure(string): bool $failsOn
     */
    private static function connection(\Closure $failsOn): Connection
    {
        return new class ($failsOn) implements Connection {
            /** @param \Closure(string): bool $failsOn */
            public function __construct(private readonly \Closure $failsOn) {}

            public function fetchAll(string $sql, array $params = []): array
            {
                $this->check($sql);

                return str_starts_with($sql, 'WITH q AS') ? [[
                    'total' => 1, 'fts_n' => 1, 'fuzzy_n' => 0, 'id' => '1', 'score' => 1.0, 'r_text' => 1.0, 'r_fuzzy' => 0.0,
                    'relevance' => 1.0, 'exact_bonus' => 0.0, 'prefix_bonus' => 0.0, 'boost_bonus' => 0.0, 'recency_bonus' => 0.0,
                ]] : [];
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                $this->check($sql);

                return null;
            }

            public function execute(string $sql, array $params = []): int
            {
                $this->check($sql);

                return 0;
            }

            public function transactional(callable $callback): mixed
            {
                return $callback($this);
            }

            private function check(string $sql): void
            {
                if (($this->failsOn)($sql)) {
                    throw new \PDOException('boom');
                }
            }
        };
    }
}
