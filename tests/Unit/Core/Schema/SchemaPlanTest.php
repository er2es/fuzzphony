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
}
