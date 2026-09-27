<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * Every Fuzzphony object in a schema that is on nobody's search_path: nothing may rely on the
 * search_path to find the sidecar table, the queue, the functions or the text configuration.
 * The fixtures (fz_product, fz_brand) stay in public, like an application's tables.
 */
final class DedicatedSchemaTest extends TestCase
{
    private const string SCHEMA = 'fuzzphony_s';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->connection->execute('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        $this->connection->execute('SET search_path TO public');
    }

    protected function tearDown(): void
    {
        $this->connection->execute('RESET search_path');
        $this->connection->execute('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
    }

    public function testEveryObjectLivesInTheConfiguredSchema(): void
    {
        $this->fuzzphony('queue');

        foreach (['fuzzphony_products', 'fuzzphony_queue'] as $table) {
            self::assertNotNull($this->connection->fetchValue(sprintf("SELECT to_regclass('%s.%s')", self::SCHEMA, $table)), $table);
            self::assertNull($this->connection->fetchValue(sprintf("SELECT to_regclass('public.%s')", $table)), $table . ' must not be created in public');
        }
        $functions = array_map(Coerce::str(...), array_column($this->connection->fetchAll(
            'SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = :schema ORDER BY 1',
            ['schema' => self::SCHEMA],
        ), 'proname'));
        self::assertSame(['fuzzphony_norm', 'fuzzphony_refresh_products', 'fuzzphony_sync_products__fz_brand', 'fuzzphony_sync_products__fz_product'], $functions);
        self::assertTrue((bool) $this->connection->fetchValue(
            "SELECT EXISTS (SELECT 1 FROM pg_ts_config c JOIN pg_namespace n ON n.oid = c.cfgnamespace WHERE c.cfgname = 'fuzzphony_english' AND n.nspname = :schema)",
            ['schema' => self::SCHEMA],
        ));
    }

    public function testSearchNeedsNeitherSchemaOnTheSearchPath(): void
    {
        $fuzzphony = $this->fuzzphony('manual');
        $this->connection->execute('SET search_path TO pg_catalog');

        self::assertContains(1, $fuzzphony->in('products')->query('mouse')->get()->ids(), 'exact full-text match');
        $fuzzy = $fuzzphony->in('products')->query('headphnoes')->get();
        self::assertTrue($fuzzy->usedFuzzy);
        self::assertSame(3, $fuzzy->hits[0]->id ?? null, 'typo-tolerant (trigram) match');
        $relaxed = $fuzzphony->in('products')->query('wireless mouse offfice')->get();
        self::assertSame([1], $relaxed->ids());
        self::assertSame(5, $fuzzphony->in('products')->get()->total, 'browsing');
    }

    public function testQueueAndTruncateSyncWorkFromASessionThatSeesNeitherSchema(): void
    {
        $fuzzphony = $this->fuzzphony('queue');
        $index = $fuzzphony->registry()->get('products');
        $engine = $fuzzphony->engine();

        $this->connection->execute("UPDATE fz_brand SET name = 'Logitech G' WHERE id = 1"); // products 1 and 4
        $this->connection->execute('SET search_path TO pg_catalog');
        self::assertSame(2, $engine->queueSize($index));
        self::assertSame(2, (new Worker($engine))->runOnce([$index]));
        self::assertEqualsCanonicalizing([1, 4], $fuzzphony->in('products')->query('brand:"logitech g"')->thresholds(['fuzzy_mode' => 'never'])->get()->ids());

        $this->connection->execute('TRUNCATE public.fz_product');
        (new Worker($engine))->runOnce([$index]);
        self::assertSame(0, $fuzzphony->in('products')->get()->total);
    }

    public function testTriggerSyncCallsTheQualifiedRefreshFunction(): void
    {
        $fuzzphony = $this->fuzzphony('trigger');

        $this->connection->execute("UPDATE fz_brand SET name = 'Zebra' WHERE id = 1");

        self::assertEqualsCanonicalizing([1, 4], $fuzzphony->in('products')->query('zebra')->thresholds(['fuzzy_mode' => 'never'])->get()->ids());
    }

    public function testDropRemovesTheIndexFromTheSchema(): void
    {
        $fuzzphony = $this->fuzzphony('queue');
        $this->connection->execute("UPDATE fz_brand SET name = 'Queued' WHERE id = 1");

        $fuzzphony->engine()->dropSchema($fuzzphony->registry()->get('products'))->apply($this->connection);

        self::assertNull($this->connection->fetchValue(sprintf("SELECT to_regclass('%s.fuzzphony_products')", self::SCHEMA)));
        self::assertSame(0, Coerce::int($this->connection->fetchValue(sprintf('SELECT count(*) FROM %s.fuzzphony_queue', self::SCHEMA))));
        self::assertSame(0, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM pg_trigger WHERE tgname LIKE 'fuzzphony\\_sync\\_products%'")));
    }

    private function fuzzphony(string $sync): Fuzzphony
    {
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection, schema: self::SCHEMA), new IndexRegistry([Indexes::products($sync)]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }
}
