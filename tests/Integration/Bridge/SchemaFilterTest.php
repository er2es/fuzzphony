<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bridge;

use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Schema\Table;
use Fuzzphony\Bridge\Doctrine\SchemaAssetFilter;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Sql\Sql;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\TestCase;

/** The schema_filter the bundle prepends hides Fuzzphony's tables from Doctrine's schema tools (migrations:diff). */
final class SchemaFilterTest extends TestCase
{
    private const string SCHEMA = 'fuzzphony_f';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->connection->execute('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        // leftovers of other tests (tsvector columns DBAL cannot map) must not decide this test
        foreach ($this->connection->fetchAll("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'fuzzphony\\_%'") as $row) {
            $this->connection->execute('DROP TABLE ' . Sql::ident('public.' . Coerce::str($row['tablename'])) . ' CASCADE');
        }
    }

    protected function tearDown(): void
    {
        $this->connection->execute('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
    }

    public function testFuzzphonysTablesInPublicAreHidden(): void
    {
        $this->apply('public');

        $tables = $this->introspectedTables(SchemaAssetFilter::regex('public'));

        self::assertNotSame([], array_filter($tables, static fn(string $t): bool => str_ends_with($t, 'fz_product')), 'application tables stay visible');
        self::assertSame([], array_values(array_filter($tables, static fn(string $t): bool => str_contains($t, 'fuzzphony_'))));
    }

    /** Positive control: without the filter the same introspection does see Fuzzphony's tables. */
    public function testWithoutTheFilterFuzzphonysTablesAreIntrospected(): void
    {
        $this->apply('public');
        $this->apply(self::SCHEMA);

        $tables = $this->introspectedTables('~~');

        self::assertContains('fuzzphony_queue', $tables);
        self::assertContains(self::SCHEMA . '.fuzzphony_queue', $tables);
    }

    public function testADedicatedSchemaIsHiddenAsAWhole(): void
    {
        $this->apply(self::SCHEMA);

        $tables = $this->introspectedTables(SchemaAssetFilter::regex(self::SCHEMA));

        self::assertNotSame([], array_filter($tables, static fn(string $t): bool => str_ends_with($t, 'fz_product')));
        self::assertSame([], array_values(array_filter($tables, static fn(string $t): bool => str_starts_with($t, self::SCHEMA . '.'))));
    }

    private function apply(string $schema): void
    {
        (new Fuzzphony(new PostgresEngine($this->connection, schema: $schema), new IndexRegistry([Indexes::products('manual')])))->schema()->apply($this->connection);
    }

    /** @return list<string> */
    private function introspectedTables(string $regex): array
    {
        $dbal = DoctrineTestCase::dbalConnection();
        // like DoctrineBundle's RegexSchemaAssetFilter: DBAL passes table names as strings, sequences as assets
        $dbal->getConfiguration()->setSchemaAssetsFilter(
            static fn(string|AbstractAsset $asset): bool => preg_match($regex, is_string($asset) ? $asset : $asset->getName()) === 1,
        );

        return array_map(static fn(Table $t): string => $t->getName(), array_values($dbal->createSchemaManager()->introspectSchema()->getTables()));
    }
}
