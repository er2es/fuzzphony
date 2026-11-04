<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/** The doctor's "Coverage" check of a multi-source index: each source's rows against the indexed documents of its kind. */
final class MultiSourceCoverageCheckTest extends TestCase
{
    private Connection $connection;
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->tearDown();
        $this->connection->execute('CREATE TABLE cv_a (id bigint PRIMARY KEY, title text NOT NULL)');
        $this->connection->execute('CREATE TABLE cv_b (id bigint PRIMARY KEY, title text NOT NULL)');
    }

    protected function tearDown(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS cv_a, cv_b, fuzzphony_cv, fuzzphony_cv__vocab, fuzzphony_cv__next, fuzzphony_cv__changes, fuzzphony_queue, fuzzphony_watches, fuzzphony_watch_log, fuzzphony_meta CASCADE');
    }

    private function install(int $rowsA, int $rowsB = 0, bool $withQuerySource = false): void
    {
        $this->connection->execute(sprintf("INSERT INTO cv_a SELECT g, 'title ' || g FROM generate_series(1, %d) AS g", $rowsA));
        if ($rowsB > 0) {
            $this->connection->execute(sprintf("INSERT INTO cv_b SELECT g, 'title ' || g FROM generate_series(1, %d) AS g", $rowsB));
        }
        $builder = IndexDefinition::builder('cv')->sourceFromTable('a', 'cv_a')->sourceFromTable('b', 'cv_b')->field('title', 'A')->sync('manual');
        if ($withQuerySource) {
            $builder->source('c', 'SELECT id, title FROM cv_b');
        }
        $index = $builder->build();
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index]));
        $this->fuzzphony->schema()->apply($this->connection);
        $this->fuzzphony->reindex('cv');
    }

    private function coverage(bool $deep = true): Check
    {
        foreach ($this->fuzzphony->inspect('cv', new InspectOptions(deep: $deep))->checks as $check) {
            if ($check->name === 'Coverage') {
                return $check;
            }
        }
        self::fail('no Coverage check');
    }

    public function testNineteenOfTwentyIsStillCoveredAndEighteenIsNot(): void
    {
        $this->install(20);
        $this->connection->execute("DELETE FROM fuzzphony_cv WHERE id = 'a:20'");
        self::assertSame('ok', $this->coverage()->status->value);

        $this->connection->execute("DELETE FROM fuzzphony_cv WHERE id = 'a:19'");
        $warn = $this->coverage();
        self::assertSame('warning', $warn->status->value);
        self::assertSame('a: 18 of 20 (exact); b: 0 of 0 (exact).', $warn->message);
        self::assertSame('bin/console fuzzphony:reindex cv --source=<source>', $warn->fix);
    }

    public function testTwentyOneIndexedOfTwentyIsStillCoveredAndTwentyOneOfNineteenIsNot(): void
    {
        $this->install(21);
        $this->connection->execute('DELETE FROM cv_a WHERE id = 21');
        self::assertSame('ok', $this->coverage()->status->value);
        self::assertSame('a: 21 of 20 (exact); b: 0 of 0 (exact)', $this->coverage()->message);

        $this->connection->execute('DELETE FROM cv_a WHERE id = 20');
        self::assertSame('warning', $this->coverage()->status->value);
    }

    public function testAnEmptySourceIsCoveredByDefinition(): void
    {
        $this->install(0, 0);

        $check = $this->coverage();

        self::assertSame('ok', $check->status->value);
        self::assertSame('a: 0 of 0 (exact); b: 0 of 0 (exact)', $check->message);
    }

    public function testWithoutDeepTheTableSourcesAreEstimatedAndAQuerySourceHasNoEstimate(): void
    {
        $this->install(3, 2, true);
        $this->connection->execute("UPDATE pg_class SET reltuples = 3 WHERE oid = 'cv_a'::regclass");
        $this->connection->execute("UPDATE pg_class SET reltuples = 2 WHERE oid = 'cv_b'::regclass");

        $check = $this->coverage(false);

        self::assertSame('a: 3 of 3 (estimated); b: 2 of 2 (estimated); c: no estimate for a query source', $check->message);
        self::assertSame('ok', $check->status->value);
    }
}
