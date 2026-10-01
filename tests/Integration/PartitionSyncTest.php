<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A partitioned watched table, two levels deep: truncating one partition is followed. One
 * partition has a name that needs quoting, another lives in a second schema.
 */
final class PartitionSyncTest extends TestCase
{
    private const string TRIGGER = 'fuzzphony_sync_parts__fz_part_trn';

    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows()); // resets queue and meta
        $this->connection->execute('DROP TABLE IF EXISTS fz_part, fuzzphony_parts, fuzzphony_parts__next, fuzzphony_parts__changes, fuzzphony_plain, fz_part_a1, "FzMixed", fuzzphony_mixed, fuzzphony_mixed__next, fuzzphony_mixed__changes CASCADE');
        $this->connection->execute('DROP SCHEMA IF EXISTS fz_part_other CASCADE');
        $this->connection->execute('CREATE SCHEMA fz_part_other');
        $this->connection->execute('CREATE TABLE fz_part (id bigint PRIMARY KEY, name text NOT NULL) PARTITION BY RANGE (id)');
        $this->connection->execute('CREATE TABLE fz_part_a PARTITION OF fz_part FOR VALUES FROM (1) TO (100) PARTITION BY RANGE (id)');
        $this->connection->execute('CREATE TABLE fz_part_a1 PARTITION OF fz_part_a FOR VALUES FROM (1) TO (50)');
        $this->connection->execute('CREATE TABLE "fz part ""A2""" PARTITION OF fz_part_a FOR VALUES FROM (50) TO (100)');
        $this->connection->execute('CREATE TABLE fz_part_other.fz_part_b PARTITION OF fz_part FOR VALUES FROM (100) TO (200)');
        $this->connection->execute("INSERT INTO fz_part VALUES (1, 'alpha lamp'), (60, 'beta lamp'), (150, 'gamma lamp')");
    }

    protected function tearDown(): void
    {
        $this->connection->fetchValue('SELECT pg_advisory_unlock_all()');
    }

    /** @return iterable<string, array{string, TriggerLevel}> */
    public static function modes(): iterable
    {
        foreach (['queue', 'trigger'] as $sync) {
            foreach (TriggerLevel::cases() as $level) {
                yield sprintf('%s sync, %s level', $sync, $level->value) => [$sync, $level];
            }
        }
    }

    #[DataProvider('modes')]
    public function testTruncatingOneLeafPartitionIsFollowed(string $sync, TriggerLevel $level): void
    {
        [$index, $fuzzphony] = $this->install($sync, $level);
        self::assertEqualsCanonicalizing([1, 60, 150], $this->lamps($fuzzphony));

        $this->connection->execute('TRUNCATE fz_part_a1');
        $this->follow($sync, $index);
        self::assertEqualsCanonicalizing([60, 150], $this->lamps($fuzzphony));

        $this->connection->execute('TRUNCATE "fz part ""A2"""');
        $this->follow($sync, $index);
        self::assertSame([150], $this->lamps($fuzzphony));

        $this->connection->execute('TRUNCATE fz_part_other.fz_part_b');
        $this->follow($sync, $index, rebuild: false); // the source is empty now: the index is wiped inline
        self::assertSame([], $this->lamps($fuzzphony));
    }

    #[DataProvider('modes')]
    public function testTruncatingTheParentEmptiesTheIndex(string $sync, TriggerLevel $level): void
    {
        [$index, $fuzzphony] = $this->install($sync, $level);

        $this->connection->execute('TRUNCATE fz_part');

        self::assertFalse($this->engine->rebuildRequested($index));
        self::assertSame(0, $this->engine->queueSize($index));
        self::assertSame([], $this->lamps($fuzzphony));
    }

    public function testEveryPartitionAtEveryLevelCarriesTheTrigger(): void
    {
        [, $fuzzphony] = $this->install('queue', TriggerLevel::Row);

        self::assertSame(['fz part "A2"', 'fz_part', 'fz_part_a', 'fz_part_a1', 'fz_part_b'], $this->carriers());
        $check = $this->check($fuzzphony, 'Partitions of fz_part');
        self::assertSame(CheckStatus::Ok, $check->status);
        self::assertSame('4 partition(s), each with the TRUNCATE trigger', $check->message);
    }

    public function testAPartitionAttachedAfterTheApplyIsReportedAndFixedByApply(): void
    {
        [, $fuzzphony] = $this->install('queue', TriggerLevel::Row);
        $this->connection->execute('CREATE TABLE fz_part_c PARTITION OF fz_part FOR VALUES FROM (200) TO (300)');
        $this->connection->execute('CREATE TABLE "fz part D" PARTITION OF fz_part FOR VALUES FROM (300) TO (400)');

        $checks = array_values(array_filter($fuzzphony->inspect('parts')->checks, static fn(Check $c): bool => $c->name === 'Partitions of fz_part'));
        self::assertCount(1, $checks);
        self::assertSame(CheckStatus::Error, $checks[0]->status);
        self::assertSame(self::TRIGGER . ' missing on "fz part D", fz_part_c: a TRUNCATE of such a partition leaves stale documents in the index.', $checks[0]->message);
        self::assertSame('bin/console fuzzphony:schema --apply', $checks[0]->fix);

        $fuzzphony->schema()->apply($this->connection);

        self::assertSame('6 partition(s), each with the TRUNCATE trigger', $this->check($fuzzphony, 'Partitions of fz_part')->message);
        $fuzzphony->schema()->apply($this->connection);
        self::assertCount(7, $this->carriers(), 'a second apply changes nothing');
    }

    public function testStatementLevelSyncWarnsAboutWritesThatTargetAPartition(): void
    {
        [, $statement] = $this->install('queue', TriggerLevel::Statement);
        $warning = array_values(array_filter($statement->inspect('parts')->checks, static fn(Check $c): bool => $c->name === 'Partitions of fz_part' && $c->status === CheckStatus::Warning));
        self::assertCount(1, $warning);
        self::assertSame('Writes that target a partition directly are not synced (statement-level triggers cannot go on partitions); use trigger_level: row, or write through the parent.', $warning[0]->message);
        self::assertNull($warning[0]->fix);

        [, $row] = $this->install('queue', TriggerLevel::Row);
        self::assertSame([], array_values(array_filter($row->inspect('parts')->checks, static fn(Check $c): bool => $c->status === CheckStatus::Warning && $c->name === 'Partitions of fz_part')));
    }

    public function testATableThatIsNotPartitionedHasNoPartitionCheck(): void
    {
        $index = IndexDefinition::builder('plain')->fromTable('fz_product')->field('name', 'A')->build();
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);

        self::assertSame([], array_values(array_filter($fuzzphony->inspect('plain')->checks, static fn(Check $c): bool => str_starts_with($c->name, 'Partitions of'))));
        $this->engine->dropSchema($index)->apply($this->connection);
    }

    public function testAModeWithoutTriggersAndDropRemoveThePartitionTriggers(): void
    {
        $this->install('queue', TriggerLevel::Row);
        [, $manual] = $this->install('manual', TriggerLevel::Row);
        self::assertSame([], $this->carriers());
        self::assertSame([], array_values(array_filter($manual->inspect('parts')->checks, static fn(Check $c): bool => $c->name === 'Partitions of fz_part')), 'no triggers expected, none checked');

        [$index] = $this->install('trigger', TriggerLevel::Row);
        self::assertCount(5, $this->carriers());
        $this->engine->dropSchema($index)->apply($this->connection);
        self::assertSame([], $this->carriers());
    }

    public function testADetachedPartitionKeepsItsTriggerUntilTheApplyOrDropRemovesIt(): void
    {
        [$index, $fuzzphony] = $this->install('queue', TriggerLevel::Row);
        $this->connection->execute('ALTER TABLE fz_part_a DETACH PARTITION fz_part_a1');

        $warning = $this->check($fuzzphony, 'Partitions of fz_part', CheckStatus::Warning);
        self::assertSame(self::TRIGGER . ' is left on fz_part_a1: a TRUNCATE there still triggers a full resync of the index.', $warning->message);
        self::assertSame('bin/console fuzzphony:schema --apply', $warning->fix);
        self::assertSame('3 partition(s), each with the TRUNCATE trigger', $this->check($fuzzphony, 'Partitions of fz_part')->message);

        $fuzzphony->schema()->apply($this->connection);
        self::assertSame(['fz part "A2"', 'fz_part', 'fz_part_a', 'fz_part_b'], $this->carriers());
        self::assertSame([], array_values(array_filter($fuzzphony->inspect('parts')->checks, static fn(Check $c): bool => $c->name === 'Partitions of fz_part' && $c->status === CheckStatus::Warning)));
        $this->connection->execute('TRUNCATE fz_part_a1');
        self::assertFalse($this->engine->rebuildRequested($index), 'no longer watched');
    }

    #[DataProvider('modes')]
    public function testDropRemovesTheTriggerOfADetachedPartition(string $sync, TriggerLevel $level): void
    {
        [$index] = $this->install($sync, $level);
        $this->connection->execute('ALTER TABLE fz_part_a DETACH PARTITION fz_part_a1');

        $this->engine->dropSchema($index)->apply($this->connection);

        self::assertSame([], $this->carriers());
        self::assertFalse((bool) $this->connection->fetchValue("SELECT to_regprocedure('fuzzphony_sync_parts__fz_part()') IS NOT NULL"));
    }

    public function testAModeWithoutTriggersRemovesTheTriggerOfADetachedPartition(): void
    {
        $this->install('queue', TriggerLevel::Row);
        $this->connection->execute('ALTER TABLE fz_part_a DETACH PARTITION fz_part_a1');
        $manual = new Fuzzphony($this->engine, new IndexRegistry([IndexDefinition::builder('parts')->fromTable('fz_part')->field('name', 'A')->sync('manual')->build()]));

        self::assertSame(
            self::TRIGGER . ' is left on "fz part ""A2""", fz_part_a, fz_part_a1, fz_part_other.fz_part_b: a TRUNCATE there still triggers a full resync of the index.',
            $this->check($manual, 'Partitions of fz_part', CheckStatus::Warning)->message,
            'before the apply every partition keeps it',
        );

        $manual->schema()->apply($this->connection);
        self::assertSame([], $this->carriers());
    }

    public function testADisabledPartitionTriggerIsReportedWithTheCommandsThatEnableIt(): void
    {
        [, $fuzzphony] = $this->install('queue', TriggerLevel::Row);
        $this->connection->execute(sprintf('ALTER TABLE fz_part_a1 DISABLE TRIGGER %s', self::TRIGGER));
        $this->connection->execute(sprintf('ALTER TABLE "fz part ""A2""" DISABLE TRIGGER %s', self::TRIGGER));
        $this->connection->execute('CREATE TABLE fz_part_c PARTITION OF fz_part FOR VALUES FROM (200) TO (300)');

        $checks = array_values(array_filter($fuzzphony->inspect('parts')->checks, static fn(Check $c): bool => $c->name === 'Partitions of fz_part'));
        self::assertCount(2, $checks);
        self::assertSame([CheckStatus::Error, CheckStatus::Error], [$checks[0]->status, $checks[1]->status]);
        self::assertSame(self::TRIGGER . ' missing on fz_part_c: a TRUNCATE of such a partition leaves stale documents in the index.', $checks[0]->message);
        self::assertSame(self::TRIGGER . ' exists but is DISABLED on "fz part ""A2""", fz_part_a1', $checks[1]->message);
        $enable = 'ALTER TABLE "fz part ""A2""" ENABLE TRIGGER "' . self::TRIGGER . '"; ALTER TABLE fz_part_a1 ENABLE TRIGGER "' . self::TRIGGER . '";';
        self::assertSame($enable, $checks[1]->fix);

        foreach (explode('; ', $enable) as $statement) {
            $this->connection->execute($statement);
        }
        $fuzzphony->schema()->apply($this->connection);
        self::assertSame('5 partition(s), each with the TRUNCATE trigger', $this->check($fuzzphony, 'Partitions of fz_part')->message);
    }

    public function testAMixedCaseWatchedTableGetsItsPartitionCheck(): void
    {
        $this->connection->execute('CREATE TABLE "FzMixed" (id bigint PRIMARY KEY, name text NOT NULL) PARTITION BY RANGE (id)');
        $this->connection->execute('CREATE TABLE "FzMixed_a" PARTITION OF "FzMixed" FOR VALUES FROM (1) TO (100)');
        $index = IndexDefinition::builder('mixed')->fromTable('FzMixed')->field('name', 'A')->build();
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);

        self::assertSame('1 partition(s), each with the TRUNCATE trigger', $this->check($fuzzphony, 'Partitions of FzMixed', index: 'mixed')->message);
        $this->engine->dropSchema($index)->apply($this->connection);
    }

    /** @return array{IndexDefinition, Fuzzphony} */
    private function install(string $sync, TriggerLevel $level): array
    {
        $index = IndexDefinition::builder('parts')->fromTable('fz_part')->field('name', 'A')->sync($sync)->triggerLevel($level)->build();
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('parts');

        return [$index, $fuzzphony];
    }

    /** Queue mode: exactly one rebuild job, which the worker runs; trigger mode resynced inline. */
    private function follow(string $sync, IndexDefinition $index, bool $rebuild = true): void
    {
        if ($sync !== 'queue') {
            return;
        }
        self::assertSame($rebuild, $this->engine->rebuildRequested($index), 'one rebuild job');
        self::assertSame($rebuild ? 1 : 0, $this->engine->queueSize($index));
        (new Worker($this->engine))->runOnce([$index]);
    }

    /** @return list<int|string> */
    private function lamps(Fuzzphony $fuzzphony): array
    {
        return $fuzzphony->in('parts')->query('lamp')->thresholds(['fuzzy_mode' => 'never'])->get()->ids();
    }

    /** @return list<string> the tables with the TRUNCATE trigger */
    private function carriers(): array
    {
        return array_map(Coerce::str(...), array_column($this->connection->fetchAll(
            'SELECT c.relname FROM pg_trigger AS g JOIN pg_class AS c ON c.oid = g.tgrelid WHERE g.tgname = :trigger ORDER BY c.relname COLLATE "C"',
            ['trigger' => self::TRIGGER],
        ), 'relname'));
    }

    private function check(Fuzzphony $fuzzphony, string $name, ?CheckStatus $status = null, string $index = 'parts'): Check
    {
        return array_find($fuzzphony->inspect($index)->checks, static fn(Check $c): bool => $c->name === $name && ($status === null ? $c->status !== CheckStatus::Warning : $c->status === $status))
            ?? self::fail(sprintf('no "%s" check', $name));
    }
}
