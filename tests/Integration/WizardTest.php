<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
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
        $connection = PostgresTestCase::connect($this);
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
        (new PostgresIntrospector(PostgresTestCase::connect($this)))->describe('no_such_table');
    }
}
