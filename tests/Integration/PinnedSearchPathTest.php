<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * The generated functions read the source tables through the schemas the apply resolved them in, with pg_temp last: neither a
 * caller's own schema ("$user") nor a temporary table of the calling session can stand in for a source table.
 */
final class PinnedSearchPathTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->connection->execute('DROP SCHEMA IF EXISTS fz_pin_role CASCADE');
        $this->connection->execute("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'fz_pin_role') THEN EXECUTE 'DROP OWNED BY fz_pin_role'; DROP ROLE fz_pin_role; END IF; END \$\$");
        (new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([Indexes::products()])))->schema()->apply($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->execute('RESET ROLE');
        $this->connection->execute('DROP SCHEMA IF EXISTS fz_pin_role CASCADE');
        $this->connection->execute("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'fz_pin_role') THEN EXECUTE 'DROP OWNED BY fz_pin_role'; DROP ROLE fz_pin_role; END IF; END \$\$");
    }

    private function brandOf(int $id): string
    {
        return Coerce::str($this->connection->fetchValue('SELECT z_brand FROM fuzzphony_products WHERE id = :id', ['id' => $id]));
    }

    public function testTheFunctionsPinTheResolvedSchemasWithPgTempLast(): void
    {
        $configs = $this->connection->fetchAll("SELECT p.proname, array_to_string(p.proconfig, ' ') AS c FROM pg_proc AS p WHERE p.oid IN (to_regprocedure('fuzzphony_refresh_products(bigint[])'), to_regprocedure('fuzzphony_refresh_products__next(bigint[])'), to_regprocedure('fuzzphony_drain_products()'), to_regprocedure('fuzzphony_watch_install(text,text)'), to_regprocedure('fuzzphony_norm(text)'), to_regprocedure('fuzzphony_track_products()')) OR p.proname ~ '^fuzzphony_watch__fz_(product|brand)_[0-9a-f]{8}$'");

        self::assertCount(8, $configs, 'refresh, rebuild refresh, drain, installer, normaliser, track and the two watch trigger functions');
        foreach ($configs as $row) {
            $config = Coerce::str($row['c'] ?? '');
            self::assertStringNotContainsString('$user', $config, Coerce::str($row['proname']));
            self::assertMatchesRegularExpression('/search_path=.*pg_temp$/', $config, Coerce::str($row['proname']));
        }
    }

    public function testATemporaryTableOfTheCallerDoesNotStandInForASourceTable(): void
    {
        $this->connection->execute("CREATE TEMPORARY TABLE fz_brand (id bigint, name text, country text)");
        $this->connection->execute("INSERT INTO pg_temp.fz_brand SELECT id, 'HIJACKED', '' FROM public.fz_brand");

        $this->connection->execute('SELECT fuzzphony_refresh_products(ARRAY[1]::bigint[])');

        self::assertNotSame('hijacked', $this->brandOf(1));
        $this->connection->execute('DROP TABLE pg_temp.fz_brand');
    }

    public function testACallersOwnSchemaDoesNotStandInForASourceTable(): void
    {
        $this->connection->execute('CREATE ROLE fz_pin_role');
        $this->connection->execute('GRANT ALL ON ALL TABLES IN SCHEMA public TO fz_pin_role');
        $this->connection->execute('CREATE SCHEMA fz_pin_role AUTHORIZATION fz_pin_role');
        $this->connection->execute('SET ROLE fz_pin_role');
        $this->connection->execute('CREATE TABLE fz_pin_role.fz_brand (id bigint, name text, country text)');
        $this->connection->execute("INSERT INTO fz_pin_role.fz_brand SELECT id, 'HIJACKED', '' FROM public.fz_brand");

        $this->connection->execute('SELECT public.fuzzphony_refresh_products(ARRAY[1]::bigint[])');
        $this->connection->execute('RESET ROLE');

        self::assertNotSame('hijacked', $this->brandOf(1));
    }
}
