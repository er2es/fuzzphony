<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Bundle\Command\DoctorCommand;
use Fuzzphony\Bundle\Command\SchemaCommand;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\Inspection\PrivilegeCheck;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\Grants;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The least-privilege matrix of docs/roles.md, proved with real roles: each role gets exactly the statements
 * `fuzzphony:schema --dump-grants` prints for it, runs its own operations, and is refused everything else.
 */
final class RoleGrantsTest extends TestCase
{
    private const string SCHEMA = 'fz_b4';

    private Connection $connection;
    private PostgresEngine $engine;
    private Fuzzphony $fuzzphony;
    private IndexDefinition $index;
    private Names $names;

    /** @var array<string, string> role kind => PostgreSQL role */
    private array $roles = [];

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        if ($this->connection->fetchValue('SELECT rolsuper OR rolcreaterole FROM pg_roles WHERE rolname = current_user') !== true) {
            self::markTestSkipped('The connection user cannot create roles.');
        }
        $this->connection->execute('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        $this->connection->execute('CREATE SCHEMA ' . self::SCHEMA);
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->names = new Names('public', self::SCHEMA);
        $this->engine = new PostgresEngine($this->connection, 'public', self::SCHEMA, searchLog: true);
        $this->index = Indexes::products('queue');
        $this->fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$this->index]));
        foreach (['search', 'worker', 'admin'] as $kind) {
            $this->roles[$kind] = sprintf('fz_b4_%s_%d', $kind, getmypid());
            $this->connection->execute('DROP ROLE IF EXISTS ' . $this->roles[$kind]);
            $this->connection->execute('CREATE ROLE ' . $this->roles[$kind]);
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }
        $this->connection->execute('RESET ROLE');
        $this->connection->execute('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE'); // first: the watched tables' triggers depend on its functions
        foreach ($this->roles as $role) {
            $this->connection->execute('DROP OWNED BY ' . $role);
            $this->connection->execute('DROP ROLE ' . $role);
        }
    }

    private function applyAsSuperuser(): void
    {
        $this->fuzzphony->schema()->apply($this->connection);
        $this->fuzzphony->reindex('products');
    }

    /** @return list<string> the GRANT statements --dump-grants prints for the role, comments left out */
    private function dump(string $kind): array
    {
        $tester = new CommandTester(new SchemaCommand($this->fuzzphony, $this->connection, new Grants($this->names, true)));
        self::assertSame(0, $tester->execute(['--dump-grants' => true, '--role' => $kind, '--to' => $this->roles[$kind]]));
        $display = $tester->getDisplay(true);
        self::assertStringNotContainsString('not there yet', $display);

        return array_values(array_filter(array_map('trim', explode("\n", $display)), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '--')));
    }

    private function grant(string $kind): void
    {
        foreach ($this->dump($kind) as $statement) {
            $this->connection->execute($statement);
        }
    }

    private function become(string $kind): void
    {
        $this->connection->execute('SET ROLE ' . $this->roles[$kind]);
    }

    /** Runs $sql as the current role and asserts PostgreSQL refuses it for lack of a privilege. */
    private function assertDenied(string $sql): void
    {
        try {
            $this->connection->execute($sql);
            self::fail('Expected a privilege error for: ' . $sql);
        } catch (\Throwable $e) {
            $message = '';
            for ($previous = $e; $previous !== null; $previous = $previous->getPrevious()) {
                $message .= $previous->getMessage() . "\n";
            }
            self::assertMatchesRegularExpression('/permission denied|must be owner/', $message, $sql);
        }
    }

    public function testTheSearchRoleSearchesLogsAndWritesTheWatchedTablesAndNothingElse(): void
    {
        $this->applyAsSuperuser();
        $this->grant('search');
        $this->connection->execute('GRANT SELECT, UPDATE ON fz_product TO ' . $this->roles['search']); // the application's own right on its own table
        $logged = Coerce::int($this->connection->fetchValue('SELECT count(*) FROM ' . $this->names->searchLog()));
        $watched = Coerce::int($this->connection->fetchValue('SELECT count(*) FROM ' . $this->names->watchLog()));

        $this->become('search');
        self::assertNotSame([], $this->fuzzphony->in('products')->query('mouse')->get()->ids());
        self::assertNotSame([], $this->fuzzphony->in('products')->query('headphnoes')->get()->ids(), 'fuzzy: the trigram branch and the vocabulary');
        self::assertSame(1, $this->engine->writeSearchLog([new SearchRecord(new \DateTimeImmutable(), 'products', null, null, 'mouse', 1, 1.0, false, RecordStatus::Ok)]));
        $this->connection->execute("UPDATE fz_product SET name = 'Wireless mouse v9' WHERE id = 1");

        foreach ([
            'SELECT * FROM ' . $this->names->searchLog(),
            'SELECT * FROM ' . $this->names->queue(),
            'SELECT * FROM ' . $this->names->watches(),
            'SELECT * FROM ' . $this->names->watchLog(),
            'INSERT INTO ' . $this->names->sidecar($this->index) . ' (id) VALUES (999)',
            'DELETE FROM ' . $this->names->sidecar($this->index),
            'UPDATE ' . $this->names->meta() . " SET index_name = index_name",
            'DELETE FROM ' . $this->names->watchLog(),
            'CREATE TABLE ' . self::SCHEMA . '.fz_nope (id int)',
            'CREATE TABLE public.fz_nope (id int)',
            'DROP TABLE ' . $this->names->sidecar($this->index),
            'ALTER TABLE ' . $this->names->queue() . ' ADD COLUMN nope int',
            'DELETE FROM ' . $this->names->vocabulary($this->index),
            'DELETE FROM fz_brand',
        ] as $sql) {
            $this->assertDenied($sql);
        }
        $this->connection->execute('RESET ROLE');
        self::assertSame($logged + 1, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM ' . $this->names->searchLog())));
        self::assertGreaterThan($watched, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM ' . $this->names->watchLog())), 'the shared trigger appended as the search role');
    }

    public function testTheWorkerRoleDrainsAndRefreshesAndHasNoDdl(): void
    {
        $this->applyAsSuperuser();
        $this->grant('worker');
        $this->connection->execute("UPDATE fz_product SET name = 'Wireless mouse v9' WHERE id = 1");

        $this->become('worker');
        $worker = new Worker($this->engine);
        self::assertGreaterThan(0, $worker->runOnce([$this->index], 100));
        self::assertSame([], $worker->queueFailures());
        self::assertSame([], $worker->rebuildFailures());
        $this->engine->recordRebuildFailure($this->index, 'recorded by the worker');
        self::assertSame(0, $this->engine->queueSize($this->index));
        foreach ([
            'CREATE TABLE ' . self::SCHEMA . '.fz_nope (id int)',
            'CREATE TABLE public.fz_nope (id int)',
            'DROP TABLE ' . $this->names->sidecar($this->index),
            'ALTER TABLE ' . $this->names->queue() . ' ADD COLUMN nope int',
            'CREATE INDEX fz_nope ON ' . $this->names->sidecar($this->index) . ' (id)',
            'UPDATE fz_product SET name = \'x\'',
            'DELETE FROM fz_brand',
            'SELECT * FROM ' . $this->names->searchLog(),
        ] as $sql) {
            $this->assertDenied($sql);
        }
        $this->connection->execute('RESET ROLE');
        self::assertContains(1, $this->fuzzphony->in('products')->query('v9')->get()->ids(), 'the worker refreshed the sidecar row');
    }

    public function testTheWorkerRoleRebuildsInPlaceAfterATruncate(): void
    {
        $this->applyAsSuperuser();
        $this->grant('worker');
        $this->connection->execute('TRUNCATE fz_brand CASCADE');

        $this->become('worker');
        $worker = new Worker($this->engine);
        $worker->runOnce([$this->index], 100);
        self::assertSame([], $worker->queueFailures());
        self::assertSame([], $worker->rebuildFailures(), 'without DDL the rebuild runs in place, on the worker grants alone');
        $this->connection->execute('RESET ROLE');
        self::assertSame(0, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM ' . $this->names->sidecar($this->index))));
    }

    public function testTheAdminRoleAppliesTheSchemaReindexesAndOwnsWhatItCreates(): void
    {
        $this->connection->execute('DROP SCHEMA ' . self::SCHEMA . ' CASCADE');
        $this->connection->execute('CREATE SCHEMA ' . self::SCHEMA);
        $this->grant('admin');

        $this->become('admin');
        $this->fuzzphony->schema()->apply($this->connection);
        $result = $this->fuzzphony->reindex('products');
        self::assertTrue($result->swapped, 'with CREATE on the schema and ownership the reindex builds next to the live table');
        self::assertGreaterThan(0, $result->written);
        $this->connection->execute('RESET ROLE');

        self::assertSame($this->roles['admin'], $this->connection->fetchValue(sprintf("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = to_regclass('%s')", $this->names->sidecar($this->index))));
        $this->become('admin');
        $this->fuzzphony->schema()->apply($this->connection); // idempotent, as the same role again
        self::assertTrue($this->fuzzphony->inspect('products')->status() !== CheckStatus::Error);
    }

    public function testTheAdminRoleWithoutTheDumpedGrantsCannotApply(): void
    {
        $this->become('admin');
        $this->expectException(EngineFailure::class);
        $this->fuzzphony->schema()->apply($this->connection);
    }

    /** A second session that runs as the role: what a separate DBAL connection of that database user is. */
    private function sessionOf(string $kind): Connection
    {
        $session = PostgresTestCase::connect();
        $session->execute('SET ROLE ' . $this->roles[$kind]);

        return $session;
    }

    /** @param array<string, Connection> $subjects */
    private function privileges(array $subjects): Check
    {
        return (new PrivilegeCheck($this->connection, new Grants($this->names, true), $subjects))->check($this->index);
    }

    public function testTheDoctorNamesWhatEachRoleLacksAndTheFix(): void
    {
        $this->applyAsSuperuser();
        $this->grant('search');
        $worker = $this->sessionOf('worker');

        $check = $this->privileges(['search' => $this->sessionOf('search'), 'worker' => $worker]);

        self::assertSame(CheckStatus::Error, $check->status);
        self::assertStringContainsString(sprintf('worker "%s" lacks', $this->roles['worker']), $check->message);
        self::assertStringContainsString('SELECT, UPDATE, DELETE on table ' . $this->names->watchLog(), $check->message, 'FOR UPDATE needs UPDATE: the check knows the whole set');
        self::assertStringContainsString('UPDATE on table ' . $this->names->watches(), $check->message);
        self::assertStringNotContainsString('search "', $check->message, 'the search role holds everything it needs');
        self::assertSame(sprintf('run the output of bin/console fuzzphony:schema products --dump-grants --role=worker --to=%s as the owner of the objects (docs/roles.md)', $this->roles['worker']), $check->fix);

        $this->grant('worker');
        self::assertSame(CheckStatus::Ok, $this->privileges(['search' => $this->sessionOf('search'), 'worker' => $worker])->status, 'after the printed grants nothing is missing');
    }

    public function testTheDoctorDoesNotFailOnAWatchesTableThatLacksTheNewColumns(): void
    {
        $this->applyAsSuperuser();
        $this->grant('worker');
        $this->connection->execute('ALTER TABLE ' . $this->names->watches() . ' DROP COLUMN bad_rows');

        $check = $this->privileges(['worker' => $this->sessionOf('worker')]);

        self::assertSame(CheckStatus::Error, $check->status);
        self::assertStringContainsString('UPDATE on table ' . $this->names->watches(), $check->message);
        $this->connection->execute('ALTER TABLE ' . $this->names->watches() . ' ADD COLUMN bad_rows bigint NOT NULL DEFAULT 0');
    }

    public function testTheDoctorNeverReportsAnOwnerOrASuperuser(): void
    {
        $this->applyAsSuperuser();

        $check = $this->privileges(['search' => $this->connection, 'worker' => $this->connection, 'admin' => $this->connection]);

        self::assertSame(CheckStatus::Ok, $check->status, $check->message);
    }

    public function testTheDoctorAsksTheAdminRoleForOwnershipAndTheSchemaRights(): void
    {
        $this->applyAsSuperuser();
        $this->grant('admin');

        $check = $this->privileges(['admin' => $this->sessionOf('admin')]);

        self::assertSame(CheckStatus::Error, $check->status, $check->message);
        self::assertStringContainsString('ownership of ' . $this->names->sidecar($this->index), $check->message, 'the objects belong to the superuser, not to the admin role');
        self::assertStringNotContainsString('CREATE on schema', $check->message);
    }

    public function testTheDoctorCommandShowsThePrivilegesRowAndFailsOnAMissingGrant(): void
    {
        $this->applyAsSuperuser();
        $privileges = new PrivilegeCheck($this->connection, new Grants($this->names, true), ['search' => $this->sessionOf('search')]);
        $tester = new CommandTester(new DoctorCommand($this->fuzzphony, null, self::SCHEMA, true, $privileges));

        $status = $tester->execute([]);

        self::assertSame(1, $status, 'the search role has no grants yet');
        self::assertStringContainsString('Privileges', $tester->getDisplay(true));
        self::assertStringContainsString('--dump-grants --role=search', $tester->getDisplay(true));
        $this->grant('search');
        $tester->execute(['--format' => 'prometheus']);
        self::assertStringContainsString('fuzzphony_doctor_check{index="products",check="Privileges"} 1', $tester->getDisplay(true));
    }

    public function testTheDoctorSkipsTheWorkerForAnIndexWithoutTheQueue(): void
    {
        $check = (new PrivilegeCheck($this->connection, new Grants($this->names, true), ['worker' => $this->sessionOf('worker')]))->check(Indexes::products('manual'));

        self::assertSame(CheckStatus::Ok, $check->status);
        self::assertSame('nothing to check', $check->message);
    }
}
