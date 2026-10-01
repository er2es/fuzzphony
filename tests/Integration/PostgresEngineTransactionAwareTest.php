<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Database\TransactionAware;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * Against a real PdoConnection (not the fake in tests/Unit/Postgres/PostgresEngineTransactionAwareTest.php,
 * whose inTransaction() is a fixed flag, not derived from a real PDO handle the way withSimilarityThreshold()
 * actually reads it).
 */
final class PostgresEngineTransactionAwareTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
    }

    public function testNoOuterTransactionSkipsTheRestoreRoundTrip(): void
    {
        $this->seed();
        $recorder = new RecordingPdoConnection($this->connection);
        $search = new Fuzzphony(new PostgresEngine($recorder), new IndexRegistry([Indexes::products('manual')]));

        $search->in('products')->query('mouse')->thresholds(['fuzzy_mode' => 'always'])->get();

        self::assertSame(1, $recorder->setConfigCalls());
    }

    public function testAnOuterTransactionKeepsBothRoundTripsAndLeavesTheSettingUnchanged(): void
    {
        $this->seed();
        $recorder = new RecordingPdoConnection($this->connection);
        $search = new Fuzzphony(new PostgresEngine($recorder), new IndexRegistry([Indexes::products('manual')]));

        $recorder->transactional(function (Connection $c) use ($search): void {
            $before = $c->fetchValue("SELECT current_setting('pg_trgm.word_similarity_threshold', true)");

            $search->in('products')->query('mouse')->thresholds(['fuzzy_mode' => 'always'])->get();

            $after = $c->fetchValue("SELECT current_setting('pg_trgm.word_similarity_threshold', true)");
            self::assertSame($before, $after, "the caller's own transaction must see the setting unchanged after the search returns");
        });

        self::assertSame(2, $recorder->setConfigCalls());
    }

    /** Schema + reindex through a plain, unrecorded engine/connection — only the search itself is recorded. */
    private function seed(): void
    {
        $engine = new PostgresEngine($this->connection);
        $fuzzphony = new Fuzzphony($engine, new IndexRegistry([Indexes::products('manual')]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');
    }
}

/** @internal Delegates everything to a real PdoConnection, recording every statement passed through it. */
final class RecordingPdoConnection implements Connection, TransactionAware
{
    /** @var list<string> */
    private array $sql = [];

    public function __construct(private readonly Connection $delegate) {}

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->sql[] = $sql;

        return $this->delegate->fetchAll($sql, $params);
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $this->sql[] = $sql;

        return $this->delegate->fetchValue($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->sql[] = $sql;

        return $this->delegate->execute($sql, $params);
    }

    public function transactional(callable $callback): mixed
    {
        return $this->delegate->transactional(fn(Connection $c): mixed => $callback($this));
    }

    public function inTransaction(): bool
    {
        return $this->delegate instanceof TransactionAware && $this->delegate->inTransaction();
    }

    public function setConfigCalls(): int
    {
        return count(array_filter($this->sql, static fn(string $s): bool => str_contains($s, 'set_config')));
    }
}
