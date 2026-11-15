<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\Names;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** An index installed by 0.12.6 (sync function and triggers without the name hash) is migrated by schema --apply and removed by drop. */
final class LegacySyncFunctionUpgradeTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        $this->connection->execute('DROP TABLE IF EXISTS fz_part, fz_plain, fuzzphony_parts, fuzzphony_plain, fuzzphony_queue, fuzzphony_watches, fuzzphony_watch_log, fuzzphony_meta CASCADE');
        $this->connection->execute('DROP FUNCTION IF EXISTS fuzzphony_sync_parts__fz_part(), fuzzphony_sync_plain__fz_plain()');
        $this->connection->execute('CREATE TABLE fz_part (id bigint PRIMARY KEY, name text NOT NULL) PARTITION BY RANGE (id)');
        $this->connection->execute('CREATE TABLE fz_part_a PARTITION OF fz_part FOR VALUES FROM (1) TO (100)');
        $this->connection->execute('CREATE TABLE fz_plain (id bigint PRIMARY KEY, name text NOT NULL)');
        $this->connection->execute("INSERT INTO fz_part VALUES (1, 'alpha lamp')");
        $this->connection->execute("INSERT INTO fz_plain VALUES (1, 'alpha lamp')");
    }

    /** @return iterable<string, array{string, TriggerLevel}> */
    public static function modes(): iterable
    {
        yield 'trigger, row' => ['trigger', TriggerLevel::Row];
        yield 'trigger, statement' => ['trigger', TriggerLevel::Statement];
        yield 'queue, row' => ['queue', TriggerLevel::Row];
    }

    #[DataProvider('modes')]
    public function testAPartitionedTableOfAnEarlierReleaseIsUpgraded(string $sync, TriggerLevel $level): void
    {
        $index = $this->index('parts', 'fz_part', $sync, $level);
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index, $other = $this->index('plain', 'fz_plain', $sync, $level)]));
        $fuzzphony->schema()->apply($this->connection);
        $this->downgrade($index, 'fz_part', partition: 'fz_part_a');
        $this->downgrade($other, 'fz_plain');

        $fuzzphony->schema()->apply($this->connection);

        self::assertSame(0, $this->legacyObjects($index), 'no legacy function or trigger left on the partitioned table');
        self::assertSame(0, $this->legacyObjects($other), 'nor on the plain one');
    }

    #[DataProvider('modes')]
    public function testDroppingAnIndexOfAnEarlierReleaseRemovesItsOldTriggers(string $sync, TriggerLevel $level): void
    {
        $index = $this->index('plain', 'fz_plain', $sync, $level);
        (new Fuzzphony($this->engine, new IndexRegistry([$index])))->schema()->apply($this->connection);
        $this->downgrade($index, 'fz_plain');

        $this->engine->dropSchema($index)->apply($this->connection);

        self::assertSame(0, $this->legacyObjects($index));
        $this->connection->execute("INSERT INTO fz_plain VALUES (2, 'beta')");
        self::assertSame(2, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fz_plain')));
    }

    private function index(string $name, string $table, string $sync, TriggerLevel $level): IndexDefinition
    {
        return IndexDefinition::builder($name)->fromTable($table)->field('name', 'A')->sync($sync)->triggerLevel($level)->build();
    }

    /** Replaces the new sync function and triggers by what 0.12.6 installed: the unhashed function, its triggers, a TRUNCATE trigger on the partition. */
    private function downgrade(IndexDefinition $index, string $table, ?string $partition = null): void
    {
        $names = new Names('public');
        $watch = $index->effectiveWatches()[0];
        $old = $names->legacySyncFunctionName($index, $watch);
        $this->connection->execute(sprintf("DROP FUNCTION IF EXISTS %s() CASCADE", $names->syncFunction($index, $watch)));
        $this->connection->execute(sprintf("CREATE FUNCTION %s() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN PERFORM fuzzphony_refresh_%s(ARRAY[1]::bigint[]); RETURN NULL; END'", $old, $index->name));
        $this->connection->execute(sprintf('CREATE TRIGGER %s AFTER INSERT OR UPDATE OR DELETE ON %s FOR EACH %s EXECUTE FUNCTION %s()', $old, $table, $index->triggerLevel === TriggerLevel::Statement ? 'STATEMENT' : 'ROW', $old));
        $this->connection->execute(sprintf('CREATE TRIGGER %s AFTER TRUNCATE ON %s FOR EACH STATEMENT EXECUTE FUNCTION %s()', Names::limit($old . '_trn'), $partition ?? $table, $old));
    }

    /** The unhashed function of $index and the triggers that use it, anywhere. */
    private function legacyObjects(IndexDefinition $index): int
    {
        $old = (new Names('public'))->legacySyncFunctionName($index, $index->effectiveWatches()[0]);

        return Coerce::int($this->connection->fetchValue('SELECT (SELECT count(*) FROM pg_proc WHERE proname = :f) + (SELECT count(*) FROM pg_trigger AS g JOIN pg_proc AS p ON p.oid = g.tgfoid WHERE p.proname = :f)', ['f' => $old]));
    }
}
