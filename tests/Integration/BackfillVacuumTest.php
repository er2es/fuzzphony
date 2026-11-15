<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Bundle\Command\SchemaCommand;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\ColumnMap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Backfill bloat (0.12.5): the backfill updates every row of another column map, leaving an old version of each behind. When it
 * updated rows, the apply runs `VACUUM (ANALYZE)` of the sidecar right after it, outside a transaction (the old versions are
 * reusable at once); schema --apply reports the growth and that fuzzphony:reindex compacts the table online; the doctor notes a
 * sidecar whose rows still hold the columns the current map keeps empty. Nothing of this runs when nothing was backfilled.
 */
final class BackfillVacuumTest extends TestCase
{
    private const string TABLE = '"public"."fuzzphony_bv"';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->drop();
        $this->connection->execute('CREATE TABLE bv_doc (id integer PRIMARY KEY, name text NOT NULL, secret text NOT NULL)');
        $this->connection->execute("INSERT INTO bv_doc SELECT i, 'kettle number ' || i || ' of the copper series', 'walrus tusk ' || i FROM generate_series(1, 10000) AS i");
    }

    protected function tearDown(): void
    {
        $this->drop();
    }

    private function drop(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS bv_doc, fuzzphony_bv, fuzzphony_bv__next, fuzzphony_bv__changes, fuzzphony_bv__vocab, fuzzphony_queue, fuzzphony_meta CASCADE');
    }

    private function fuzzphony(bool $granted): Fuzzphony
    {
        $b = IndexDefinition::builder('bv')->fromTable('bv_doc')->field('name', 'A', fuzzy: true);
        if ($granted) {
            $b = $b->field('secret', 'B', fuzzy: true, granted: 'ROLE_X');
        }

        return new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$b->sync('manual')->build()]));
    }

    /** One field (its `z_name` and `t_name` kept empty), reindexed, then a granted field added: every row waits for the backfill. */
    private function pending(): Fuzzphony
    {
        $one = $this->fuzzphony(false);
        $one->schema()->apply($this->connection);
        $one->reindex('bv');

        return $this->fuzzphony(true);
    }

    private function vacuumed(): bool
    {
        $this->connection->execute('SELECT pg_stat_clear_snapshot()');

        return $this->connection->fetchValue("SELECT last_vacuum IS NOT NULL FROM pg_stat_user_tables WHERE relid = 'fuzzphony_bv'::regclass") === true;
    }

    /** @return list<Check> */
    private static function sizeNotes(Fuzzphony $fuzzphony): array
    {
        return array_values(array_filter($fuzzphony->inspect('bv')->checks, static fn(Check $c): bool => $c->name === 'Sidecar size'));
    }

    public function testThePlanVacuumsRightAfterTheBackfillOutsideATransactionWhenItUpdatedRows(): void
    {
        $statements = $this->fuzzphony(true)->schema()->statements;
        $at = array_key_first(array_filter($statements, static fn(Statement $s): bool => str_starts_with($s->description, ColumnMap::BACKFILL)));
        self::assertIsInt($at);
        $vacuum = $statements[$at + 1];

        self::assertSame('VACUUM (ANALYZE) ' . self::TABLE, $vacuum->sql);
        self::assertSame(ColumnMap::VACUUM . ' ("bv")', $vacuum->description);
        self::assertFalse($vacuum->transactional, 'VACUUM cannot run in a transaction');
        self::assertSame("SELECT 1 AS name WHERE current_setting('fuzzphony.backfilled', true) = '\"public\".\"fuzzphony_bv\"'", $vacuum->forEach);
        self::assertStringContainsString("PERFORM set_config('fuzzphony.backfilled', '\"public\".\"fuzzphony_bv\"', false);", $statements[$at]->sql);
    }

    public function testAnApplyThatBackfilledRowsVacuumsAndTheCommandReportsTheGrowth(): void
    {
        $two = $this->pending();
        self::assertSame([], self::sizeNotes($two), 'rows of another map are not "wider than needed" (the backfill warning covers them)');
        self::assertFalse($this->vacuumed());

        $tester = new CommandTester(new SchemaCommand($two, $this->connection));
        self::assertSame(Command::SUCCESS, $tester->execute(['--apply' => true, '--force' => true], ['interactive' => false]));
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay(true));

        self::assertTrue($this->vacuumed(), 'the VACUUM ran (outside a transaction: inside one it fails)');
        self::assertMatchesRegularExpression('/bv: the backfill grew the table from (\d+\.\d) MB to (\d+\.\d) MB/', $display);
        preg_match('/from (\d+\.\d) MB to (\d+\.\d) MB/', $display, $m);
        self::assertGreaterThan((float) ($m[1] ?? 0), (float) ($m[2] ?? 0));
        self::assertStringContainsString('Run fuzzphony:reindex bv to compact it online', $display);

        $notes = self::sizeNotes($two);
        self::assertCount(1, $notes, 'the doctor notes the backfilled rows');
        self::assertStringContainsString('10000 of 10000 document(s) store the per-field columns', $notes[0]->message);
        self::assertSame('bin/console fuzzphony:reindex bv', $notes[0]->fix);

        $two->reindex('bv');
        self::assertSame([], self::sizeNotes($two), 'the reindex writes every row with the current map');
    }

    public function testNothingBackfilledMeansNoVacuumAndNoReport(): void
    {
        $two = $this->fuzzphony(true);
        $two->schema()->apply($this->connection);
        $two->reindex('bv');

        $tester = new CommandTester(new SchemaCommand($two, $this->connection));
        self::assertSame(Command::SUCCESS, $tester->execute(['--apply' => true], ['interactive' => false]));

        self::assertFalse($this->vacuumed());
        self::assertStringNotContainsString('the backfill grew', $tester->getDisplay(true));
        self::assertSame([], self::sizeNotes($two));
    }

    public function testTheLibraryApplyVacuumsOnlyAfterABackfillThatUpdatedRows(): void
    {
        $two = $this->pending();
        $two->schema()->apply($this->connection);
        self::assertSame(self::TABLE, $this->connection->fetchValue("SELECT current_setting('fuzzphony.backfilled', true)"));
        self::assertTrue($this->vacuumed());

        $two->schema()->apply($this->connection); // nothing left: the setting is cleared, no second VACUUM
        self::assertSame('', $this->connection->fetchValue("SELECT current_setting('fuzzphony.backfilled', true)"));
    }

    public function testTheDumpedMigrationNamesTheVacuumAsAHintAndDoesNotRunIt(): void
    {
        $directory = sys_get_temp_dir() . '/fz-bv-' . bin2hex(random_bytes(4));
        $tester = new CommandTester(new SchemaCommand($this->pending(), $this->connection));
        self::assertSame(Command::SUCCESS, $tester->execute(['--dump-migration' => $directory]));
        $files = glob($directory . '/Version*.php');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        $migration = (string) file_get_contents($files[0]);
        unlink($files[0]);
        rmdir($directory);

        self::assertStringContainsString('// ' . ColumnMap::VACUUM . ' ("bv"): not run here; when the backfill above updated rows, run "VACUUM (ANALYZE) ' . self::TABLE . '" outside a transaction afterwards', $migration);
        self::assertStringNotContainsString("addSql('VACUUM", $migration);
        self::assertStringContainsString(ColumnMap::BACKFILL, $migration);
        self::assertFalse($this->vacuumed());
    }
}
