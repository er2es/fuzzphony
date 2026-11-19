<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Hardening;

use Fuzzphony\Bundle\Command\SchemaCommand;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\Inspection\PrivilegeCheck;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\Grants;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The three roles end to end on a database without any Fuzzphony object (a superuser created the two extensions): the admin role does the very first apply
 * with the grants --dump-grants prints for it, the worker drains, the search role searches (with returnable columns), a writer
 * with table grants only writes a watched table in trigger mode, and the doctor's privilege check is clean for each.
 */
#[Group('hardening')]
final class FreshInstallRolesTest extends TestCase
{
    private Connection $c;

    /** @var array<string, string> */
    private array $roles = [];

    protected function setUp(): void
    {
        $this->c = Harness::fresh();
        if ($this->c->fetchValue('SELECT rolsuper FROM pg_roles WHERE rolname = current_user') !== true) {
            self::markTestSkipped('needs a superuser connection');
        }
        $this->c->execute('DROP EXTENSION IF EXISTS pg_trgm CASCADE');
        $this->c->execute('DROP EXTENSION IF EXISTS unaccent CASCADE');
        Harness::fixtures($this->c);
        foreach (['admin', 'worker', 'search', 'writer'] as $kind) {
            $this->roles[$kind] = sprintf('fz_h_%s', $kind);
            $this->dropRole($this->roles[$kind]);
            $this->c->execute(sprintf('CREATE ROLE %s', $this->roles[$kind]));
        }
    }

    protected function tearDown(): void
    {
        $this->c->execute('RESET ROLE');
        foreach (Harness::OWN_SCHEMAS as $schema) {
            $this->c->execute(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $schema));
        }
        $this->c->execute('DROP SCHEMA IF EXISTS public CASCADE');
        $this->c->execute('CREATE SCHEMA public');
        foreach ($this->roles as $role) {
            $this->dropRole($role);
        }
    }

    private function dropRole(string $role): void
    {
        if ($this->c->fetchValue('SELECT 1 FROM pg_roles WHERE rolname = :r', ['r' => $role]) !== null) {
            $this->c->execute(sprintf('DROP OWNED BY %s CASCADE', $role));
            $this->c->execute(sprintf('DROP ROLE %s', $role));
        }
    }

    /** @return list<string> */
    private function dump(Fuzzphony $f, Names $names, string $kind, string $role): array
    {
        $tester = new CommandTester(new SchemaCommand($f, $this->c, new Grants($names, true)));
        self::assertSame(0, $tester->execute(['--dump-grants' => true, '--role' => $kind, '--to' => $role]));

        return array_values(array_filter(array_map('trim', explode("\n", $tester->getDisplay(true))), static fn(string $l): bool => $l !== '' && !str_starts_with($l, '--')));
    }

    private function session(string $kind): Connection
    {
        $s = PostgresTestCase::connect();
        $s->execute(sprintf('SET ROLE %s', $this->roles[$kind]));

        return $s;
    }

    /** @return iterable<string, array{string, string}> */
    public static function installs(): iterable
    {
        yield 'trigger mode, own schema' => ['trigger', 'fuzzphony'];
        yield 'queue mode, own schema' => ['queue', 'fuzzphony'];
        yield 'trigger mode, public' => ['trigger', 'public'];
        yield 'queue mode, public' => ['queue', 'public'];
    }

    #[DataProvider('installs')]
    public function testTheThreeRolesOnAFreshInstall(string $sync, string $schema): void
    {
        $index = Indexes::products($sync)->withReturnable(['name', 'brand']);
        $names = new Names('public', $schema);
        $root = new Fuzzphony(new PostgresEngine($this->c, 'public', $schema, searchLog: true), new IndexRegistry([$index]));
        // what the dump says: CREATE EXTENSION needs a superuser (or the database owner, the extensions being trusted)
        $this->c->execute('CREATE EXTENSION pg_trgm WITH SCHEMA public');
        $this->c->execute('CREATE EXTENSION unaccent WITH SCHEMA public');
        foreach ($this->dump($root, $names, 'admin', $this->roles['admin']) as $statement) {
            $this->c->execute($statement);
        }

        $admin = $this->session('admin');
        $f = new Fuzzphony(new PostgresEngine($admin, 'public', $schema, searchLog: true), new IndexRegistry([$index]));
        $f->schema()->apply($admin);
        $f->reindex('products');
        $f->schema()->apply($admin);

        foreach (['worker', 'search'] as $kind) {
            $dump = $this->dump($root, $names, $kind, $this->roles[$kind]);
            foreach ($dump as $statement) {
                $this->c->execute($statement);
            }
        }
        // the application's writer: the search role's table grants (docs/roles.md: they cover the sync of a write) without any
        // GRANT EXECUTE (the functions are PUBLIC's to execute), and its own rights on its own tables
        foreach ($this->dump($root, $names, 'search', $this->roles['writer']) as $statement) {
            if (!str_starts_with($statement, 'GRANT EXECUTE')) {
                $this->c->execute($statement);
            }
        }
        $this->c->execute(sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON fz_product, fz_brand TO %s', $this->roles['writer']));

        $writer = $this->session('writer');
        $writer->execute("INSERT INTO fz_product VALUES (900, 'Zebrafish lamp', 'aquarium', 1, 10, true, 1, now())");
        $writer->execute("UPDATE fz_brand SET name = 'Quokkabrand' WHERE id = 1");

        $worker = $this->session('worker');
        if ($sync === 'queue') {
            self::assertGreaterThan(0, (new Worker(new PostgresEngine($worker, 'public', $schema, searchLog: true)))->runOnce([$index]));
        }
        $search = $this->session('search');
        $s = new Fuzzphony(new PostgresEngine($search, 'public', $schema, searchLog: true), new IndexRegistry([$index]));
        $hits = $s->in('products')->query('zebrafish')->with('name', 'brand')->get()->hits;
        self::assertSame(900, $hits[0]->id ?? null);
        self::assertSame('Quokkabrand', $hits[0]->data['brand'] ?? null);
        self::assertContains(900, $s->in('products')->query('quokkabrand')->get()->ids());

        $check = (new PrivilegeCheck($this->c, new Grants($names, true), ['search' => $search, 'worker' => $worker, 'admin' => $admin]))->check($index);
        self::assertSame(CheckStatus::Ok, $check->status, $check->message);
        self::assertSame([], Harness::problems($f, 'products'), 'the doctor run by the admin role');
    }
}
