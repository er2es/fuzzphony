<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Wizard\ColumnKind;
use Fuzzphony\Core\Wizard\DefinitionSuggester;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Wizard\PostgresIntrospector;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use PHPUnit\Framework\TestCase;

/** The wizard's suggestion must work end to end on a real database, first try. */
final class WizardTest extends TestCase
{
    public function testSuggestionIsImmediatelyUsable(): void
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $introspector = new PostgresIntrospector($connection);

        $tables = array_column($introspector->tables(), 'table');
        self::assertContains('fz_product', $tables);
        self::assertNotContains('fuzzphony_queue', $tables);

        $profile = $introspector->describe('fz_product');
        self::assertSame('id', $profile->primaryKey);
        self::assertSame('fz_brand', $profile->foreignKeys[0]->referencedTable ?? null);

        $suggestion = (new DefinitionSuggester())->suggest($profile, 'wizard_products');
        $index = $suggestion->definition;
        self::assertNotNull($index, implode("\n", $suggestion->notes));
        self::assertNotNull($index->field('brand'), 'brand name joined in');

        $engine = new PostgresEngine($connection);
        $fuzzphony = new Fuzzphony($engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($connection);
        self::assertSame(5, $fuzzphony->reindex('wizard_products'));

        self::assertContains(2, $fuzzphony->in('wizard_products')->query('razer')->get()->ids());
        self::assertSame([1], $fuzzphony->in('wizard_products')->query('wireless')->where('in_stock', true)->where('price', '<', 5000)->get()->ids());
        self::assertTrue($fuzzphony->inspect('wizard_products')->isHealthy());

        $engine->dropSchema($index)->apply($connection);
    }

    public function testUnknownTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PostgresIntrospector(PostgresTestCase::connect()))->describe('no_such_table');
    }

    /**
     * Once ANALYZE has run, describe() reads real planner statistics instead of sampling:
     * a uuid column is classified as such, a near-unique column gets a negative (fraction-of-
     * rows) n_distinct that must be turned back into an absolute count, and a column with a
     * handful of repeated values gets most_common_vals, from which approximateMax is read.
     */
    public function testDescribeUsesRealPlannerStatisticsOnceAnalyzed(): void
    {
        $connection = PostgresTestCase::connect();
        $connection->execute('DROP TABLE IF EXISTS fz_stats_sample CASCADE');
        $connection->execute('CREATE TABLE fz_stats_sample (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), category int NOT NULL, serial_no int NOT NULL)');
        $connection->execute('INSERT INTO fz_stats_sample (category, serial_no) SELECT (i % 3), i FROM generate_series(1, 500) AS i');
        $connection->execute('ANALYZE fz_stats_sample');

        $profile = (new PostgresIntrospector($connection))->describe('fz_stats_sample');
        $columns = [];
        foreach ($profile->columns as $column) {
            $columns[$column->name] = $column;
        }

        self::assertSame(ColumnKind::Uuid, $columns['id']->kind);

        // serial_no is unique in the sample: PostgreSQL records n_distinct as a negative fraction of the row count
        self::assertNotNull($columns['serial_no']->distinct);
        self::assertGreaterThan(400, $columns['serial_no']->distinct);

        // category only has 3 distinct values, so they all become most_common_vals
        self::assertNotNull($columns['category']->approximateMax);
        self::assertGreaterThanOrEqual(2.0, $columns['category']->approximateMax);

        $connection->execute('DROP TABLE fz_stats_sample');
    }
}
