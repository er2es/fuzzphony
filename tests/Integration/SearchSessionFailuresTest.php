<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** What a search reports when the transaction around its statements, or the savepoint inside the caller's, cannot be closed. */
final class SearchSessionFailuresTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([Indexes::products('manual')]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');
    }

    private function search(Connection $connection): int
    {
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual')]));

        return count($fuzzphony->in('products')->query('mouze')->get()->hits);
    }

    public function testAFailingCommitIsAnEngineFailureOfTheSearch(): void
    {
        $failing = new class ($this->connection) implements Connection {
            public function __construct(private readonly Connection $inner) {}

            public function fetchAll(string $sql, array $params = []): array
            {
                return $this->inner->fetchAll($sql, $params);
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                return $this->inner->fetchValue($sql, $params);
            }

            public function execute(string $sql, array $params = []): int
            {
                return $this->inner->execute($sql, $params);
            }

            public function transactional(callable $callback): mixed
            {
                $this->inner->transactional(fn(): mixed => $callback($this));

                throw new \PDOException('COMMIT failed');
            }

            public function inTransaction(): bool
            {
                return $this->inner->inTransaction();
            }
        };

        $this->expectException(EngineFailure::class);
        $this->search($failing);
    }

    public function testASavepointThatCannotBeReleasedInTheCallersTransactionIsAnEngineFailureAndTheTransactionIsKept(): void
    {
        $release = static function (string $sql): void {
            if (str_starts_with($sql, 'RELEASE SAVEPOINT')) {
                throw new \PDOException('release failed');
            }
        };
        $intercepting = new InterceptingConnection($this->connection, execute: $release);

        try {
            $intercepting->transactional(fn(): int => $this->search($intercepting));
            self::fail('EngineFailure expected');
        } catch (EngineFailure $e) {
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
        self::assertFalse($this->connection->inTransaction());
        self::assertSame(1, $this->connection->fetchValue('SELECT 1'));
    }
}
