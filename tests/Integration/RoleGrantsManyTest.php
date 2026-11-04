<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Bundle\Command\SchemaCommand;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\Grants;
use Fuzzphony\Engine\Postgres\Schema\Names;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** The grants of an index with several values per document cover the values table and the child table, and nothing more. */
final class RoleGrantsManyTest extends TestCase
{
    private const string SCHEMA = 'fz_h5';

    private Connection $connection;
    private Fuzzphony $fuzzphony;
    private PostgresEngine $engine;
    private Names $names;
    private IndexDefinition $index;

    /** @var array<string, string> */
    private array $roles = [];

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        if ($this->connection->fetchValue('SELECT rolsuper OR rolcreaterole FROM pg_roles WHERE rolname = current_user') !== true) {
            self::markTestSkipped('The connection user cannot create roles.');
        }
        $this->connection->execute('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        $this->connection->execute('DROP TABLE IF EXISTS rg_address, rg_customer CASCADE');
        $this->connection->execute('CREATE SCHEMA ' . self::SCHEMA);
        $this->connection->execute('CREATE TABLE rg_customer (id bigint PRIMARY KEY, name text NOT NULL)');
        $this->connection->execute('CREATE TABLE rg_address (id serial PRIMARY KEY, customer_id bigint NOT NULL, city text)');
        $this->connection->execute("INSERT INTO rg_customer VALUES (1, 'Alice'), (2, 'Bob')");
        $this->connection->execute("INSERT INTO rg_address (customer_id, city) VALUES (1, 'Budapest'), (1, 'Vienna'), (2, 'Szeged')");
        $this->names = new Names('public', self::SCHEMA);
        $this->engine = new PostgresEngine($this->connection, 'public', self::SCHEMA);
        $this->index = IndexDefinition::builder('rg_customers')
            ->fromTable('rg_customer')
            ->field('name', 'A')
            ->field('city', 'C', many: ['from' => 'rg_address', 'key' => 'customer_id', 'column' => 'city'])
            ->filter('city', 'string', many: ['from' => 'rg_address', 'key' => 'customer_id', 'column' => 'city'])
            ->sync('queue')
            ->build();
        $this->fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$this->index]));
        foreach (['search', 'worker', 'admin'] as $kind) {
            $this->roles[$kind] = sprintf('fz_h5_%s_%d', $kind, getmypid());
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
        $this->connection->execute('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        $this->connection->execute('DROP TABLE IF EXISTS rg_address, rg_customer CASCADE');
        foreach ($this->roles as $role) {
            $this->connection->execute('DROP OWNED BY ' . $role);
            $this->connection->execute('DROP ROLE ' . $role);
        }
    }

    /** @return list<string> */
    private function dump(string $kind): array
    {
        $tester = new CommandTester(new SchemaCommand($this->fuzzphony, $this->connection, new Grants($this->names, false)));
        self::assertSame(0, $tester->execute(['--dump-grants' => true, '--role' => $kind, '--to' => $this->roles[$kind]]));

        return array_values(array_filter(array_map('trim', explode("\n", $tester->getDisplay(true))), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '--')));
    }

    public function testTheDumpNamesTheValuesAndChildTables(): void
    {
        $this->fuzzphony->schema()->apply($this->connection);
        $values = $this->names->values($this->index);

        self::assertNotSame([], array_filter($this->dump('search'), static fn(string $s): bool => str_contains($s, $values) && str_contains($s, 'SELECT')));
        $worker = implode("\n", $this->dump('worker'));
        self::assertStringContainsString($values, $worker);
        self::assertStringContainsString('rg_address', $worker, 'the worker reads the child table');
        self::assertStringContainsString('rg_address', implode("\n", $this->dump('admin')), 'the admin triggers the child table');
    }

    public function testTheWorkerAndTheSearchRoleDoTheirWorkWithTheirGrantsAlone(): void
    {
        $this->fuzzphony->schema()->apply($this->connection);
        $this->fuzzphony->reindex('rg_customers');
        foreach (['search', 'worker'] as $kind) {
            foreach ($this->dump($kind) as $statement) {
                $this->connection->execute($statement);
            }
        }

        // the application writes the child table as the role the web process runs as (here the search role): its trigger appends to the shared log
        $this->connection->execute('GRANT INSERT ON rg_address TO ' . $this->roles['search']);
        $this->connection->execute('GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO ' . $this->roles['search']);
        // a highlight reads the source (documents and child rows), as it always did: the application's own right on its tables
        $this->connection->execute('GRANT SELECT ON rg_customer, rg_address TO ' . $this->roles['search']);
        $this->connection->execute('SET ROLE ' . $this->roles['search']);
        $this->connection->execute("INSERT INTO rg_address (customer_id, city) VALUES (2, 'Pecs')");
        $this->connection->execute('SET ROLE ' . $this->roles['worker']);
        (new Worker($this->engine))->runOnce([$this->index]);
        $this->connection->execute('RESET ROLE');
        self::assertSame(1, Coerce::int($this->connection->fetchValue(sprintf("SELECT count(*) FROM %s WHERE v = 'Pecs'", $this->names->values($this->index)))));

        $this->connection->execute('SET ROLE ' . $this->roles['search']);
        $hit = $this->fuzzphony->in('rg_customers')->query('pecs')->explainMatches()->highlight('city')->get()->hits[0];
        self::assertSame(2, $hit->id);
        self::assertSame('Pecs', ($hit->matches ?? [])[0]->value);
        self::assertSame('<mark>Pecs</mark>', $hit->highlights['city']);
        self::assertSame([1], $this->fuzzphony->in('rg_customers')->where('city', 'Vienna')->get()->ids());
    }
}
