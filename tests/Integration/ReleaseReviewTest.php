<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\GrantRole;
use Fuzzphony\Engine\Postgres\Schema\Grants;
use Fuzzphony\Engine\Postgres\Schema\Names;
use PHPUnit\Framework\TestCase;

/** Regressions of the independent review of the 0.10.0 release candidate (shared watch: privileges, names, poisoned log). */
final class ReleaseReviewTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;
    private string $role;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        if ($this->connection->fetchValue('SELECT rolsuper OR rolcreaterole FROM pg_roles WHERE rolname = current_user') !== true) {
            self::markTestSkipped('The connection user cannot create roles.');
        }
        $this->engine = new PostgresEngine($this->connection);
        $this->cleanLog();
        $this->connection->execute('DROP TABLE IF EXISTS rr_x, rr_orders, rr_y, rr_bad CASCADE');
        $this->connection->execute('DROP SCHEMA IF EXISTS rr CASCADE');
        $this->role = 'fz_rr_worker_' . getmypid();
        $this->connection->execute('DROP ROLE IF EXISTS ' . $this->role);
        $this->connection->execute('CREATE ROLE ' . $this->role);
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }
        $this->connection->execute('RESET ROLE');
        $this->cleanLog();
        $this->connection->execute('DROP OWNED BY ' . $this->role);
        $this->connection->execute('DROP ROLE ' . $this->role);
        $this->connection->execute('DROP TABLE IF EXISTS rr_x, rr_orders, rr_y, rr_bad CASCADE');
        $this->connection->execute('DROP SCHEMA IF EXISTS rr CASCADE');
    }

    private function cleanLog(): void
    {
        foreach (['fuzzphony_watch_log', 'fuzzphony_queue'] as $table) {
            if ($this->connection->fetchValue('SELECT to_regclass(:t) IS NOT NULL', ['t' => $table]) === true) {
                $this->connection->execute('DELETE FROM ' . $table);
            }
        }
        if ($this->connection->fetchValue("SELECT to_regclass('fuzzphony_watches') IS NOT NULL") === true) {
            $this->connection->execute("DELETE FROM fuzzphony_watches WHERE index_name LIKE 'rr\_%'");
        }
    }

    private function fuzzphony(IndexDefinition ...$indexes): Fuzzphony
    {
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry($indexes));
        $fuzzphony->schema()->apply($this->connection);

        return $fuzzphony;
    }

    private function int(string $sql): int
    {
        return Coerce::int($this->connection->fetchValue($sql));
    }

    private function index(string $name, string $table): IndexDefinition
    {
        return IndexDefinition::builder($name)->fromTable($table)->field('name', 'A')->sync('queue')->build();
    }

    public function testTheWorkerRoleCanMoveItsPositionButNotRewriteWhatTheAdminTrusts(): void
    {
        $this->connection->execute('CREATE TABLE rr_x (id int primary key, name text)');
        $index = $this->index('rr_a', 'rr_x');
        $this->fuzzphony($index);
        $names = new Names('public', 'public');
        foreach ((new Grants($names, false))->for(GrantRole::Worker, [$index]) as $grant) {
            if (!$grant->optional) {
                $this->connection->execute($grant->sql($this->role));
            }
        }

        $this->connection->execute('SET ROLE ' . $this->role);
        $this->connection->execute('SELECT cur_xid FROM fuzzphony_watches FOR UPDATE SKIP LOCKED');
        $this->connection->execute('UPDATE fuzzphony_watches SET cur_xid = cur_xid');
        foreach (['tbl', 'fn', 'key_column', 'columns', 'index_name'] as $column) {
            try {
                $this->connection->execute(sprintf("UPDATE fuzzphony_watches SET %s = %s", $column, $column));
                self::fail('The worker role rewrote fuzzphony_watches.' . $column);
            } catch (\Throwable $e) {
                self::assertStringContainsString('permission denied', $e->getMessage() . $e->getPrevious()?->getMessage());
            }
        }
    }

    public function testAForgedWatchRowCannotMakeTheApplyDropTheApplicationsOwnTriggerOrFunction(): void
    {
        $this->connection->execute('CREATE TABLE rr_x (id int primary key, name text)');
        $this->connection->execute('CREATE TABLE rr_orders (id int)');
        $this->connection->execute('CREATE OR REPLACE FUNCTION public.rr_audit() RETURNS trigger LANGUAGE plpgsql AS $$BEGIN RETURN NEW; END$$');
        $this->connection->execute('CREATE TRIGGER rr_audit_trg BEFORE INSERT ON rr_orders FOR EACH ROW EXECUTE FUNCTION public.rr_audit()');
        $index = $this->index('rr_a', 'rr_x');
        $fuzzphony = $this->fuzzphony($index);

        // what a role with plain UPDATE on the registry could once write: the application's table and function
        $this->connection->execute("UPDATE fuzzphony_watches SET tbl = '\"rr_orders\"', fn = 'rr_audit' WHERE index_name = 'rr_a'");
        $fuzzphony->schema()->apply($this->connection);
        $this->engine->dropSchema($index)->apply($this->connection);

        self::assertSame(1, $this->int("SELECT count(*) FROM pg_trigger WHERE tgname = 'rr_audit_trg'"), 'the application trigger survives');
        self::assertSame(1, $this->int("SELECT count(*) FROM pg_proc WHERE proname = 'rr_audit'"), 'the application function survives');
        $this->connection->execute('DROP TABLE IF EXISTS rr_orders CASCADE');
        $this->connection->execute('DROP FUNCTION IF EXISTS public.rr_audit()');
    }

    public function testTablesWhoseNamesDifferOnlyByTheSeparatorKeepTheirOwnTriggerFunctions(): void
    {
        $this->connection->execute('CREATE SCHEMA rr');
        $this->connection->execute('CREATE TABLE rr.x (id int primary key, name text)');
        $this->connection->execute('CREATE TABLE rr_x (id int primary key, name text)');
        $this->connection->execute("INSERT INTO rr.x VALUES (1, 'alpha')");
        $this->connection->execute("INSERT INTO rr_x VALUES (1, 'omega')");
        $a = $this->index('rr_a', 'rr.x');
        $b = $this->index('rr_b', 'rr_x');
        $fuzzphony = $this->fuzzphony($a, $b);
        $fuzzphony->reindex('rr_a');
        $fuzzphony->reindex('rr_b');

        self::assertSame(4, $this->int("SELECT count(*) FROM pg_trigger WHERE tgrelid = 'rr.x'::regclass AND NOT tgisinternal"));
        self::assertSame(4, $this->int("SELECT count(*) FROM pg_trigger WHERE tgrelid = 'rr_x'::regclass AND NOT tgisinternal"));
        self::assertSame(2, $this->int("SELECT count(DISTINCT tgfoid) FROM pg_trigger WHERE tgrelid IN ('rr.x'::regclass, 'rr_x'::regclass) AND NOT tgisinternal"), 'one function per table');
        $fuzzphony->schema()->apply($this->connection); // idempotent: the second install does not strip the first
        self::assertSame(4, $this->int("SELECT count(*) FROM pg_trigger WHERE tgrelid = 'rr.x'::regclass AND NOT tgisinternal"));
    }

    public function testAFailedFullRebuildStoresTheClassAndTheSqlStateNeverTheRowValues(): void
    {
        $this->connection->execute('CREATE TABLE rr_bad (id bigint PRIMARY KEY, label text NOT NULL, amount text NOT NULL)');
        $this->connection->execute("INSERT INTO rr_bad VALUES (1, 'one', '1'), (2, 'two', '2')");
        $index = IndexDefinition::builder('rr_a')->fromQuery('SELECT b.id, b.label, b.amount::int AS amount FROM rr_bad b')->watch('rr_bad')->field('label', 'A')->filter('amount', 'int')->sync('queue')->build();
        $fuzzphony = $this->fuzzphony($index);
        $fuzzphony->reindex('rr_a');
        $this->connection->execute("UPDATE rr_bad SET amount = 'card 4111111111111111' WHERE id = 2");
        $this->connection->execute("INSERT INTO fuzzphony_queue (index_name, doc_id) VALUES ('rr_a', '*') ON CONFLICT DO NOTHING");

        $worker = new \Fuzzphony\Core\Sync\Worker($this->engine);
        $worker->runOnce([$index]);

        $stored = Coerce::str($this->connection->fetchValue("SELECT rebuild_error FROM fuzzphony_meta WHERE index_name = 'rr_a'"));
        self::assertSame('PDOException (SQLSTATE 22P02)', $stored);
        foreach ($fuzzphony->inspect('rr_a')->checks as $check) {
            self::assertStringNotContainsString('4111111111111111', $check->message);
        }
    }

    public function testOneForgedKeyDoesNotTurnTheWholeDrainIntoAKeyByKeyRetry(): void
    {
        $this->connection->execute('CREATE TABLE rr_x (id int primary key, name text)');
        $index = $this->index('rr_a', 'rr_x');
        $this->fuzzphony($index)->reindex('rr_a');
        $this->connection->execute("INSERT INTO rr_x SELECT g, 'n' || g FROM generate_series(1, 100000) g");
        $started = microtime(true);
        $this->connection->fetchValue('SELECT fuzzphony_drain_rr_a()');
        $clean = microtime(true) - $started;
        self::assertSame(100000, $this->int("SELECT count(*) FROM fuzzphony_queue WHERE index_name = 'rr_a'"));

        $this->connection->execute("DELETE FROM fuzzphony_queue WHERE index_name = 'rr_a'");
        $this->connection->execute("UPDATE rr_x SET name = name || 'x'");
        $this->connection->execute("INSERT INTO fuzzphony_watch_log (tbl, xid, op, key_column, key) VALUES ('\"rr_x\"', pg_current_xact_id()::text::bigint, 'I', 'id', 'zz'), ('\"rr_x\"', pg_current_xact_id()::text::bigint, 'I', 'id', '99999999999')");
        $started = microtime(true);
        $this->connection->fetchValue('SELECT fuzzphony_drain_rr_a()');
        $forged = microtime(true) - $started;
        self::assertSame(100000, $this->int("SELECT count(*) FROM fuzzphony_queue WHERE index_name = 'rr_a'"), 'every legitimate key is queued');
        self::assertSame(2, $this->int("SELECT bad_rows FROM fuzzphony_watches WHERE index_name = 'rr_a'"));
        self::assertLessThan(1.3 * $clean + 0.2, $forged, 'the key-by-key retry (a subtransaction per key) made the drain several times slower');
    }

    public function testTheDoctorFindsTheTriggersAndThePrimaryKeyOfAMixedCaseTable(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS "RrMix" CASCADE');
        $this->connection->execute('CREATE TABLE "RrMix" (id int primary key, name text)');
        $index = IndexDefinition::builder('rr_mix')->fromTable('public.RrMix')->field('name', 'A')->sync('queue')->build();
        $fuzzphony = $this->fuzzphony($index);
        $fuzzphony->reindex('rr_mix');

        foreach ($fuzzphony->inspect('rr_mix')->checks as $check) {
            if (str_contains($check->name, 'trigger') || str_contains($check->name, 'Source key') || str_contains($check->message, 'primary key')) {
                self::assertContains($check->status, [CheckStatus::Ok, CheckStatus::Skipped], $check->name . ': ' . $check->message);
            }
        }
        $this->connection->execute('DROP TABLE "RrMix" CASCADE');
    }

    public function testAForgedLogRowWithAKeyThatDoesNotCastIsSkippedAndCountedAndBlocksNothing(): void
    {
        $this->connection->execute('CREATE TABLE rr_x (id int primary key, name text)');
        $this->connection->execute("INSERT INTO rr_x VALUES (1, 'alpha'), (2, 'gamma')");
        $index = $this->index('rr_a', 'rr_x');
        $fuzzphony = $this->fuzzphony($index);
        $fuzzphony->reindex('rr_a');
        // the search/application role has INSERT on the log by design: it writes a key the source cannot cast
        $this->connection->execute("INSERT INTO fuzzphony_watch_log (tbl, xid, op, key_column, key) VALUES ('\"rr_x\"', pg_current_xact_id()::text::bigint, 'I', 'id', 'not-an-int')");
        $this->connection->execute("UPDATE rr_x SET name = 'betamax' WHERE id = 1");

        $worker = new \Fuzzphony\Core\Sync\Worker($this->engine);
        self::assertSame(1, $worker->runOnce([$index]), 'the real change is applied');
        self::assertSame(1, $fuzzphony->in('rr_a')->query('betamax')->get()->total);
        self::assertSame(1, $this->int("SELECT bad_rows FROM fuzzphony_watches WHERE index_name = 'rr_a'"));

        $message = '';
        foreach ($fuzzphony->inspect('rr_a')->checks as $check) {
            $message .= $check->message . "\n";
        }
        self::assertStringContainsString('1 log row(s) for "rr_x" could not be applied', $message);
        self::assertStringNotContainsString('not-an-int', $message);

        // the sync keeps working afterwards
        $this->connection->execute("UPDATE rr_x SET name = 'zetamax' WHERE id = 2");
        self::assertSame(1, $worker->runOnce([$index]));
        self::assertSame(1, $fuzzphony->in('rr_a')->query('zetamax')->get()->total);
    }

    public function testManyForgedTruncateRowsQueueOneRebuildNotOnePerRow(): void
    {
        $this->connection->execute('CREATE TABLE rr_x (id int primary key, name text)');
        $this->connection->execute("INSERT INTO rr_x VALUES (1, 'alpha')");
        $index = $this->index('rr_a', 'rr_x');
        $fuzzphony = $this->fuzzphony($index);
        $fuzzphony->reindex('rr_a');
        for ($i = 0; $i < 20; ++$i) {
            $this->connection->execute("INSERT INTO fuzzphony_watch_log (tbl, xid, op, key_column) VALUES ('\"rr_x\"', pg_current_xact_id()::text::bigint, 'T', '')");
        }

        $this->connection->fetchValue('SELECT fuzzphony_drain_rr_a()');

        self::assertSame(1, $this->int("SELECT count(*) FROM fuzzphony_queue WHERE index_name = 'rr_a' AND doc_id = '*'"), 'one pending full rebuild, however many rows asked for it');
    }
}
