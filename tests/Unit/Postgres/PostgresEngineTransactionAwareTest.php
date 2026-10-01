<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Database\TransactionAware;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class PostgresEngineTransactionAwareTest extends TestCase
{
    public function testNoOuterTransactionSkipsTheRestoreRoundTrip(): void
    {
        $connection = self::connection(inTransaction: false);
        $engine = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products()]));

        $engine->in('products')->query('mouse')->get();

        self::assertSame(1, self::setConfigCalls($connection));
    }

    public function testAnOuterTransactionKeepsBothRoundTrips(): void
    {
        $connection = self::connection(inTransaction: true);
        $engine = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products()]));

        $engine->in('products')->query('mouse')->get();

        self::assertSame(2, self::setConfigCalls($connection));
    }

    public function testAConnectionWithoutTheCapabilityAlwaysKeepsBothRoundTrips(): void
    {
        $connection = new class implements Connection {
            /** @var list<string> */
            public array $sql = [];

            public function fetchAll(string $sql, array $params = []): array
            {
                $this->sql[] = $sql;

                return str_starts_with($sql, 'WITH q AS') ? [[
                    'total' => 1, 'fts_n' => 1, 'fuzzy_n' => 0, 'id' => '1', 'score' => 1.0, 'r_text' => 1.0, 'r_fuzzy' => 0.0,
                    'relevance' => 1.0, 'exact_bonus' => 0.0, 'prefix_bonus' => 0.0, 'boost_bonus' => 0.0, 'recency_bonus' => 0.0,
                ]] : [];
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                $this->sql[] = $sql;

                return null;
            }

            public function execute(string $sql, array $params = []): int
            {
                $this->sql[] = $sql;

                return 0;
            }

            public function transactional(callable $callback): mixed
            {
                return $callback($this);
            }
        };
        $engine = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products()]));

        $engine->in('products')->query('mouse')->get();

        self::assertSame(2, count(array_filter($connection->sql, static fn(string $s): bool => str_contains($s, 'set_config'))));
    }

    /**
     * @param object{sql: list<string>} $connection
     *
     * @return int how many statements contained set_config (1 = read+set only, 2 = read+set and restore)
     */
    private static function setConfigCalls(object $connection): int
    {
        return count(array_filter($connection->sql, static fn(string $s): bool => str_contains($s, 'set_config')));
    }

    /** @return Connection&object{sql: list<string>} */
    private static function connection(bool $inTransaction): Connection
    {
        return new class ($inTransaction) implements Connection, TransactionAware {
            /** @var list<string> */
            public array $sql = [];

            public function __construct(private readonly bool $inTransaction) {}

            public function fetchAll(string $sql, array $params = []): array
            {
                $this->sql[] = $sql;

                return str_starts_with($sql, 'WITH q AS') ? [[
                    'total' => 1, 'fts_n' => 1, 'fuzzy_n' => 0, 'id' => '1', 'score' => 1.0, 'r_text' => 1.0, 'r_fuzzy' => 0.0,
                    'relevance' => 1.0, 'exact_bonus' => 0.0, 'prefix_bonus' => 0.0, 'boost_bonus' => 0.0, 'recency_bonus' => 0.0,
                ]] : [];
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                $this->sql[] = $sql;

                return null;
            }

            public function execute(string $sql, array $params = []): int
            {
                $this->sql[] = $sql;

                return 0;
            }

            public function transactional(callable $callback): mixed
            {
                return $callback($this);
            }

            public function inTransaction(): bool
            {
                return $this->inTransaction;
            }
        };
    }
}
