<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Schema;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Schema\Statement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaPlanTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function transactionality(): iterable
    {
        yield 'inside the transaction' => [true];
        yield 'after the transaction' => [false];
    }

    #[DataProvider('transactionality')]
    public function testADatabaseErrorNamesTheFailingStatement(bool $transactional): void
    {
        $connection = new class implements Connection {
            public function inTransaction(): bool
            {
                return false;
            }

            /** @var list<string> */
            public array $executed = [];

            public function fetchAll(string $sql, array $params = []): array
            {
                return [];
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                return null;
            }

            public function execute(string $sql, array $params = []): int
            {
                if ($sql === 'BROKEN') {
                    throw new \PDOException('syntax error at or near "BROKEN"');
                }
                $this->executed[] = $sql;

                return 0;
            }

            public function transactional(callable $callback): mixed
            {
                return $callback($this);
            }
        };
        $plan = new SchemaPlan([new Statement('SELECT 1', 'first'), new Statement('BROKEN', 'second', $transactional)]);

        try {
            $plan->apply($connection);
            self::fail('EngineFailure expected');
        } catch (EngineFailure $e) {
            self::assertSame(
                "Fuzzphony schema statement \"second\" failed: syntax error at or near \"BROKEN\"\n"
                . 'Hint: Review the SQL with "bin/console fuzzphony:schema" (without --apply); every statement is safe to re-run.',
                $e->getMessage(),
            );
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
        self::assertSame(['SELECT 1'], $connection->executed);
    }

    public function testOnStatementSeesEveryStatementBeforeItRuns(): void
    {
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        $connection = new class ($log) implements Connection {
            public function inTransaction(): bool
            {
                return false;
            }

            /** @param \ArrayObject<int, string> $log */
            public function __construct(private readonly \ArrayObject $log) {}

            public function fetchAll(string $sql, array $params = []): array
            {
                return [];
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                return null;
            }

            public function execute(string $sql, array $params = []): int
            {
                $this->log[] = 'execute ' . $sql;

                return 0;
            }

            public function transactional(callable $callback): mixed
            {
                return $callback($this);
            }
        };
        $plan = new SchemaPlan([new Statement('CREATE INDEX CONCURRENTLY x', 'late', false), new Statement('SELECT 1', 'early')]);

        $plan->apply($connection, static function (Statement $s) use ($log): bool {
            $log[] = 'announce ' . $s->description;

            return false; // the callback's return value must not matter
        });

        self::assertSame(
            ['announce early', 'execute SELECT 1', 'announce late', 'execute CREATE INDEX CONCURRENTLY x'],
            $log->getArrayCopy(),
        );
    }

    public function testAStatementWaitsWhileItsWaitQueryIsTrueAndIsSkippedWhenItsSkipQueryIsTrue(): void
    {
        $connection = new class implements Connection {
            /** @var list<string> */
            public array $log = [];
            public int $waits = 2;

            public function inTransaction(): bool
            {
                return false;
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                return [];
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                $this->log[] = $sql;

                return match ($sql) {
                    'BUSY' => $this->waits-- > 0,
                    'THERE' => true,
                    default => false,
                };
            }

            public function execute(string $sql, array $params = []): int
            {
                $this->log[] = $sql;

                return 0;
            }

            public function transactional(callable $callback): mixed
            {
                return $callback($this);
            }
        };
        (new SchemaPlan([
            new Statement('BUILD A', 'a', false, skipIf: 'ABSENT', waitWhile: 'BUSY'),
            new Statement('BUILD B', 'b', false, skipIf: 'THERE'),
        ]))->apply($connection);

        self::assertSame(['BUSY', 'BUSY', 'BUSY', 'ABSENT', 'BUILD A', 'THERE'], $connection->log);
    }

    /** @return Connection&object{log: list<string>, deadlocks: int} */
    private static function deadlocking(int $deadlocks, string $sqlState = '40P01'): Connection
    {
        return new class ($deadlocks, $sqlState) implements Connection {
            /** @var list<string> */
            public array $log = [];

            public function __construct(public int $deadlocks, private readonly string $sqlState) {}

            public function inTransaction(): bool
            {
                return false;
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                $this->log[] = $sql;

                // the victim of a deadlock leaves its index INVALID: the repair finds it
                return $sql === 'LEFTOVERS' && str_contains(implode(',', $this->log), 'BUILD') ? [['name' => 'ix']] : [];
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                $this->log[] = $sql;

                return false;
            }

            public function execute(string $sql, array $params = []): int
            {
                $this->log[] = $sql;
                if ($sql === 'BUILD' && $this->deadlocks-- > 0) {
                    $e = new \PDOException('deadlock detected');
                    $e->errorInfo = [$this->sqlState, 7, 'deadlock detected'];

                    throw $e;
                }

                return 0;
            }

            public function transactional(callable $callback): mixed
            {
                return $callback($this);
            }
        };
    }

    private static function build(): Statement
    {
        return new Statement('BUILD', 'build', false, skipIf: 'VALID', waitWhile: 'BUSY', repair: new Statement('DROP {name}', 'drop', false, forEach: 'LEFTOVERS'));
    }

    public function testADeadlockedBuildWaitsDropsWhatItLeftChecksAgainAndRetries(): void
    {
        $connection = self::deadlocking(2);
        (new SchemaPlan([self::build()]))->apply($connection);

        $attempt = ['BUSY', 'LEFTOVERS', 'VALID', 'BUILD'];
        $retry = ['BUSY', 'LEFTOVERS', 'DROP ix', 'VALID', 'BUILD'];
        self::assertSame([...$attempt, ...$retry, ...$retry], $connection->log);
    }

    public function testAFailedRepairIsAWarningOnceAndTheBuildStillRuns(): void
    {
        $connection = new class implements Connection {
            /** @var list<string> */
            public array $log = [];

            public function inTransaction(): bool
            {
                return false;
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                return [['name' => 'ix']];
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                return false;
            }

            public function execute(string $sql, array $params = []): int
            {
                $this->log[] = $sql;
                if ($sql === 'FAIL ix') {
                    throw new \PDOException('denied');
                }

                return 0;
            }

            public function transactional(callable $callback): mixed
            {
                return $callback($this);
            }
        };
        $drop = new Statement('FAIL {name}', 'drop', false, forEach: 'NAMES');
        $warnings = (new SchemaPlan([$drop, new Statement('BUILD', 'build', false, waitWhile: 'BUSY', repair: $drop)]))->apply($connection);

        self::assertSame(['drop: ix failed (denied). Run it yourself: FAIL ix'], $warnings);
        self::assertSame(['FAIL ix', 'FAIL ix', 'BUILD'], $connection->log);
    }

    public function testABuildThatKeepsDeadlockingFailsAfterFiveRetries(): void
    {
        $connection = self::deadlocking(6);
        try {
            (new SchemaPlan([self::build()]))->apply($connection);
            self::fail('expected a failure');
        } catch (EngineFailure $e) {
            self::assertStringContainsString('deadlock detected', $e->getMessage());
        }
        self::assertSame(6, count(array_keys($connection->log, 'BUILD', true)));
    }

    public function testOnlyADeadlockOfAWaitingStatementIsRetried(): void
    {
        $other = self::deadlocking(1, '23505');
        try {
            (new SchemaPlan([self::build()]))->apply($other);
            self::fail('expected a failure');
        } catch (EngineFailure) {
        }
        self::assertSame(['BUSY', 'LEFTOVERS', 'VALID', 'BUILD'], $other->log);

        $plain = self::deadlocking(1);
        try {
            (new SchemaPlan([new Statement('BUILD', 'build', false)]))->apply($plain);
            self::fail('expected a failure');
        } catch (EngineFailure) {
        }
        self::assertSame(['BUILD'], $plain->log);
    }
}
