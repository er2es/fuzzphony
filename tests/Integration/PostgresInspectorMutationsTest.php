<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\Inspection\PostgresInspector;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The doctor's defaults. */
final class PostgresInspectorMutationsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_search_log CASCADE');
    }

    public function testTheDefaultRetentionOfTheSearchLogIsNinetyDays(): void
    {
        $index = Indexes::products('manual');
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection, searchLog: true), new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');
        // the retention is not given: the inspector's own default applies
        $searchLog = fn(): array => array_values(array_filter(
            (new PostgresInspector($this->connection, new PostgresSchemaGenerator(searchLog: true), true))->inspect($index, new InspectOptions())->checks,
            static fn(Check $c): bool => $c->name === 'Search log',
        ));
        $insert = fn(string $age) => $this->connection->execute(sprintf("INSERT INTO fuzzphony_search_log (at, index_name, query, query_norm, hits, took_ms, used_fuzzy, status) VALUES (now() - interval '%s', 'products', 'q', 'q', 0, 1, false, 'ok')", $age));

        $insert('89 days 12 hours');
        self::assertEquals([Check::ok('Search log', 'Table exists, nothing older than the retention.')], $searchLog(), 'still inside the 90 days');

        $insert('90 days 12 hours');
        self::assertEquals(
            [Check::warning('Search log', 'Rows older than the 90 days of retention are still in the table.', 'bin/console fuzzphony:search-log:purge')],
            $searchLog(),
        );
    }
}
