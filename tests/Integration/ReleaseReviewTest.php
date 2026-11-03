<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
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
        $this->connection->execute('DROP TABLE IF EXISTS rr_x, rr_orders, rr_y CASCADE');
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
        $this->connection->execute('DROP OWNED BY ' . $this->role);
        $this->connection->execute('DROP ROLE ' . $this->role);
        $this->connection->execute('DROP TABLE IF EXISTS rr_x, rr_orders, rr_y CASCADE');
        $this->connection->execute('DROP SCHEMA IF EXISTS rr CASCADE');
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
}
