<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/** The wording and the edges of some doctor checks: legacy index names, failing queue items, the shared change log, mixed sources. */
final class PostgresInspectorGapsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->tearDown();
        $this->connection->execute('CREATE TABLE fz_gap (id bigint PRIMARY KEY, title text NOT NULL)');
        $this->connection->execute("INSERT INTO fz_gap SELECT g, 'title ' || g FROM generate_series(1, 3) AS g");
    }

    protected function tearDown(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fz_gap, fz_gap_b, fz_gap_child, fuzzphony_gap, fuzzphony_gap__vocab, fuzzphony_gap__next, fuzzphony_gap__changes, fuzzphony_queue, fuzzphony_watches, fuzzphony_watch_log, fuzzphony_meta CASCADE');
        $this->connection->execute('DROP FUNCTION IF EXISTS fuzzphony_drain_gap()');
    }

    private function queueIndex(): IndexDefinition
    {
        return IndexDefinition::builder('gap')->fromQuery('SELECT id, title FROM fz_gap')->watch('fz_gap')->field('title', 'A')->sync('queue')->build();
    }

    private function install(IndexDefinition $index, bool $reindex = true): Fuzzphony
    {
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        if ($reindex) {
            $fuzzphony->reindex($index->name);
        }

        return $fuzzphony;
    }

    private static function check(Fuzzphony $fuzzphony, string $name, bool $deep = false): Check
    {
        foreach ($fuzzphony->inspect('gap', new InspectOptions(deep: $deep))->checks as $check) {
            if ($check->name === $name) {
                return $check;
            }
        }
        self::fail('no check ' . $name);
    }

    public function testAnIndexUnderAnOldNameIsAnErrorNamedAfterItWithTheDropToRun(): void
    {
        $fuzzphony = $this->install($this->queueIndex());
        $this->connection->execute('ALTER INDEX fuzzphony_gap__tsv RENAME TO fuzzphony_gap_tsv');

        $check = self::check($fuzzphony, 'Index fuzzphony_gap_tsv');

        self::assertSame(CheckStatus::Error, $check->status);
        self::assertStringStartsWith('OLD NAME', $check->message);
        self::assertStringContainsString('DROP INDEX CONCURRENTLY ', (string) $check->fix);
        self::assertStringContainsString('"fuzzphony_gap_tsv";', (string) $check->fix);
    }

    private function failQueue(int $items): void
    {
        $this->connection->execute("DELETE FROM fuzzphony_queue WHERE index_name = 'gap'");
        $this->connection->execute(sprintf("INSERT INTO fuzzphony_queue (index_name, doc_id, attempts, last_error, failed_at) SELECT 'gap', 'id' || g, 1, 'PDOException (SQLSTATE 22P02)', now() - g * interval '1 second' FROM generate_series(1, %d) AS g", $items));
    }

    public function testFiveFailingItemsAreAllNamedAndSixAreFiveAndTheCountOfTheRest(): void
    {
        $fuzzphony = $this->install($this->queueIndex());

        $this->failQueue(5);
        $five = self::check($fuzzphony, 'Sync queue')->message;
        self::assertSame('5 items waiting; 5 documents fail on their own and stay queued: id id1 (PDOException (SQLSTATE 22P02), 1 attempt); id id2 (PDOException (SQLSTATE 22P02), 1 attempt); id id3 (PDOException (SQLSTATE 22P02), 1 attempt); id id4 (PDOException (SQLSTATE 22P02), 1 attempt); id id5 (PDOException (SQLSTATE 22P02), 1 attempt)', $five);

        $this->failQueue(6);
        $six = self::check($fuzzphony, 'Sync queue')->message;
        self::assertStringStartsWith('6 items waiting; 6 documents fail on their own and stay queued: id id1 (', $six);
        self::assertStringEndsWith('id id5 (PDOException (SQLSTATE 22P02), 1 attempt); and 1 more', $six);
        self::assertStringNotContainsString('id id6', $six);
    }

    public function testTheSharedChangeLogNamesTheMissingWatchOrTheMissingFunction(): void
    {
        $fuzzphony = $this->install($this->queueIndex());
        self::assertSame(CheckStatus::Ok, self::check($fuzzphony, 'Shared change log')->status);

        $this->connection->execute("DELETE FROM fuzzphony_watches WHERE index_name = 'gap'");
        $watch = self::check($fuzzphony, 'Shared change log');
        self::assertSame(CheckStatus::Error, $watch->status);
        self::assertSame('"gap" is not registered as a watcher of fz_gap: its changes are logged by no one for it.', $watch->message);

        $fuzzphony->schema()->apply($this->connection);
        $this->connection->execute('DROP FUNCTION fuzzphony_drain_gap()');
        $function = self::check($fuzzphony, 'Shared change log');
        self::assertSame(CheckStatus::Error, $function->status);
        self::assertSame('The function that moves the change log into the queue is missing.', $function->message);
    }

    public function testAQuerySourceBeforeATableSourceDoesNotHideTheCoverageOfTheOnesAfterIt(): void
    {
        $index = IndexDefinition::builder('gap')->source('q', 'SELECT id, title FROM fz_gap')->sourceFromTable('t', 'fz_gap')->field('title', 'A')->sync('manual')->build();
        $fuzzphony = $this->install($index);
        $this->connection->execute("UPDATE pg_class SET reltuples = 3 WHERE oid = 'fz_gap'::regclass");

        self::assertSame('q: no estimate for a query source; t: 3 of 3 (estimated)', self::check($fuzzphony, 'Coverage')->message);
    }

    public function testAManyValuesFilterDoesNotHideTheFiltersAfterIt(): void
    {
        $this->connection->execute('CREATE TABLE fz_gap_child (id bigint PRIMARY KEY, gap_id bigint NOT NULL, city text NOT NULL)');
        $index = IndexDefinition::builder('gap')
            ->fromQuery('SELECT id, title FROM fz_gap')
            ->field('title', 'A')
            ->filter('city', 'string', many: ['from' => 'fz_gap_child', 'key' => 'gap_id', 'column' => 'city', 'limit' => 5])
            ->filter('nosuch', 'int')
            ->sync('manual')
            ->build();
        $fuzzphony = $this->install($index, false);

        $check = self::check($fuzzphony, 'Filter nosuch');
        self::assertSame(CheckStatus::Error, $check->status);
        self::assertSame('Column "nosuch" not found in source.', $check->message);
    }
}
