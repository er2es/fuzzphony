<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexBuilder;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/** The doctor's "Watches" check: tables the source reads that no watch covers (found through the view's dependencies, no SQL parsing). */
final class WatchCoverageTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->tearDown();
        $this->connection->execute('CREATE TABLE wc_customer (id bigint PRIMARY KEY, name text NOT NULL, address_id bigint)');
        $this->connection->execute('CREATE TABLE wc_address (id bigint PRIMARY KEY, city text NOT NULL)');
        $this->connection->execute('CREATE TABLE wc_note (id bigint PRIMARY KEY, title text NOT NULL)');
        $this->connection->execute('CREATE VIEW wc_customer_v AS SELECT c.id, c.name, a.city FROM wc_customer c LEFT JOIN wc_address a ON a.id = c.address_id');
        $this->connection->execute('CREATE TABLE wc_event (id bigint, title text NOT NULL, PRIMARY KEY (id)) PARTITION BY RANGE (id)');
        $this->connection->execute('CREATE TABLE wc_event_1 PARTITION OF wc_event FOR VALUES FROM (0) TO (1000)');
    }

    protected function tearDown(): void
    {
        $this->connection->execute('DROP VIEW IF EXISTS wc_customer_v');
        $this->connection->execute('DROP TABLE IF EXISTS wc_customer, wc_address, wc_note, wc_event, wc_event_1, fuzzphony_wc, fuzzphony_wc__next, fuzzphony_wc__changes, fuzzphony_wc__vocab CASCADE');
    }

    private const string JOIN = 'SELECT c.id, c.name AS title, a.city AS body FROM wc_customer c LEFT JOIN wc_address a ON a.id = c.address_id';

    /** @return array<string, list<Check>> the "Watches" checks, by check name */
    private function watches(IndexDefinition $index): array
    {
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $found = [];
        foreach ($fuzzphony->inspect($index->name, new InspectOptions())->checks as $check) {
            if (str_ends_with($check->name, 'Watches')) {
                $found[$check->name][] = $check;
            }
        }

        return $found;
    }

    private static function single(string $sql, string $sync, Watch ...$watches): IndexDefinition
    {
        $builder = IndexDefinition::builder('wc')->fromQuery($sql)->field('title', 'A')->field('body', 'C')->sync($sync);
        foreach ($watches as $watch) {
            $builder->watch($watch->table, $watch->affectedIds);
        }

        return $builder->build();
    }

    public function testAJoinedTableWithoutAWatchIsAWarningWithAFixTemplate(): void
    {
        $checks = $this->watches(self::single(self::JOIN, 'queue', new Watch('wc_customer')))['Watches'] ?? [];

        self::assertCount(1, $checks);
        self::assertSame(CheckStatus::Warning, $checks[0]->status);
        self::assertStringContainsString('"wc_address"', $checks[0]->message);
        self::assertStringContainsString("watch: { wc_address: 'SELECT id FROM <source-table> WHERE <fk> = :id' }", (string) $checks[0]->fix);
        self::assertStringContainsString('adapt the ids query', (string) $checks[0]->fix);
        self::assertStringContainsString('or set sync: manual', (string) $checks[0]->fix);
    }

    public function testNoWarningWhenEveryTableReadIsWatched(): void
    {
        $checks = $this->watches(self::single(self::JOIN, 'queue', new Watch('wc_customer'), new Watch('wc_address', 'SELECT id FROM wc_customer WHERE address_id = :id')))['Watches'] ?? [];

        self::assertCount(1, $checks);
        self::assertSame(CheckStatus::Ok, $checks[0]->status);
        self::assertStringContainsString('2 table(s)', $checks[0]->message);
    }

    public function testASourceMaintainedByHandIsNeverChecked(): void
    {
        self::assertSame([], $this->watches(self::single(self::JOIN, 'manual', new Watch('wc_customer'))));
    }

    public function testAViewSourceIsFollowedDownToItsTables(): void
    {
        $index = IndexDefinition::builder('wc')->fromQuery('SELECT id, name AS title, city AS body FROM wc_customer_v')->field('title', 'A')->field('body', 'C')->sync('trigger')->watch('wc_customer')->build();
        $checks = $this->watches($index)['Watches'] ?? [];

        self::assertCount(1, $checks);
        self::assertStringContainsString('"wc_address"', $checks[0]->message);
        self::assertStringNotContainsString('wc_customer_v', $checks[0]->message);
    }

    public function testAWatchedParentCoversItsPartitions(): void
    {
        $index = IndexDefinition::builder('wc')->fromQuery('SELECT id, title FROM wc_event_1')->field('title', 'A')->sync('queue')->watch('wc_event')->build();
        $checks = $this->watches($index)['Watches'] ?? [];

        self::assertCount(1, $checks);
        self::assertSame(CheckStatus::Ok, $checks[0]->status);
    }

    public function testAMultiSourceIndexNamesTheSourceThatMissesAWatch(): void
    {
        $index = self::multi(IndexDefinition::builder('wc')
            ->source('customer', self::JOIN, watch: [new Watch('wc_customer'), new Watch('wc_address', 'SELECT id FROM wc_customer WHERE address_id = :id')])
            ->source('note', 'SELECT n.id, n.title, a.city AS body FROM wc_note n, wc_address a WHERE a.id = n.id', watch: [new Watch('wc_note')]));
        $found = $this->watches($index);

        self::assertSame(CheckStatus::Ok, $found['Source "customer": Watches'][0]->status);
        self::assertCount(1, $found['Source "note": Watches']);
        self::assertSame(CheckStatus::Warning, $found['Source "note": Watches'][0]->status);
        self::assertStringContainsString('Source "note"', $found['Source "note": Watches'][0]->message);
        self::assertStringContainsString('"wc_address"', $found['Source "note": Watches'][0]->message);
    }

    public function testAnUnreadableSourceIsNotCheckedRatherThanAFalseError(): void
    {
        $index = self::single('SELECT id, nope AS title, nope AS body FROM wc_customer', 'queue', new Watch('wc_customer'));
        $found = $this->watches($index);

        self::assertArrayNotHasKey('Watches', $found, 'the Source check already reports it');
    }

    private static function multi(IndexBuilder $builder): IndexDefinition
    {
        return $builder->field('title', 'A')->field('body', 'C')->sync('queue')->build();
    }
}
