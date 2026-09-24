<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** PostgreSQL-specific behaviour: database-side sync, schema evolution, the doctor, highlighting. */
final class PostgresEngineTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
    }

    private function fuzzphony(string $sync): Fuzzphony
    {
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([Indexes::products($sync)]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    public function testQueueModeFollowsChangesIncludingJoinedTables(): void
    {
        $fuzzphony = $this->fuzzphony('queue');
        $index = $fuzzphony->registry()->get('products');

        $this->connection->execute("UPDATE fz_brand SET name = 'Logitech G' WHERE id = 1"); // fans out to products 1 and 4
        $this->connection->execute("INSERT INTO fz_product VALUES (6, 'Trackball', 'Ergonomic trackball', 1, 5990, true, 3, now())");
        $this->connection->execute('DELETE FROM fz_product WHERE id = 2');

        self::assertSame(4, $this->engine->queueSize($index)); // 1, 4, 6, 2 (deduplicated)
        self::assertSame(4, (new Worker($this->engine))->runOnce([$index]));
        self::assertSame(0, $this->engine->queueSize($index));

        self::assertEqualsCanonicalizing([1, 4, 6], $fuzzphony->in('products')->query('brand:"logitech g"')->thresholds(['fuzzy_mode' => 'never'])->get()->ids());
        self::assertNotContains(2, $fuzzphony->in('products')->query('gaming')->get()->ids());
    }

    public function testStatementLevelTriggersHandleBulkChangesSetBased(): void
    {
        $fuzzphony = $this->fuzzphony('queue');
        $index = $fuzzphony->registry()->get('products');
        $this->connection->execute(
            "INSERT INTO fz_product SELECT i, 'Bulk item ' || i, 'generated', 1 + i % 3, 100, true, 0, now() FROM generate_series(100, 1099) AS i",
        );
        self::assertSame(1000, $this->engine->queueSize($index), 'one statement, 1000 queued ids');

        $this->connection->execute("UPDATE fz_product SET description = 'changed' WHERE id >= 100"); // same ids: deduplicated
        self::assertSame(1000, $this->engine->queueSize($index));

        (new Worker($this->engine))->runOnce([$index], 250);
        self::assertSame(1000, $fuzzphony->in('products')->query('bulk')->thresholds(['fuzzy_mode' => 'never'])->get()->total);

        $this->connection->execute('DELETE FROM fz_product WHERE id >= 100');
        (new Worker($this->engine))->runOnce([$index]);
        self::assertSame(0, $fuzzphony->in('products')->query('bulk')->thresholds(['fuzzy_mode' => 'never'])->get()->total);
    }

    public function testSwitchingTriggerLevelLeavesNoDuplicates(): void
    {
        $this->fuzzphony('queue');
        $row = Indexes::products('queue')->with(triggerLevel: TriggerLevel::Row);
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$row]));
        $fuzzphony->schema()->apply($this->connection);

        $names = array_column($this->connection->fetchAll("SELECT tgname FROM pg_trigger WHERE tgrelid = 'fz_brand'::regclass AND NOT tgisinternal"), 'tgname');
        self::assertSame(['fuzzphony_sync_products__fz_brand'], $names);
        self::assertTrue($fuzzphony->inspect('products')->isHealthy());
    }

    public function testTriggerModeIsSynchronous(): void
    {
        $fuzzphony = $this->fuzzphony('trigger');

        $this->connection->execute("UPDATE fz_product SET name = 'Ergonomic vertical mouse' WHERE id = 2");

        self::assertSame([2], $fuzzphony->in('products')->query('ergonomic')->get()->ids());
    }

    public function testHighlightsAreHtmlSafe(): void
    {
        $fuzzphony = $this->fuzzphony('manual');
        $this->connection->execute("UPDATE fz_product SET name = 'Mouse <b onmouseover=x> & more' WHERE id = 1");
        $fuzzphony->refresh('products', [1]);

        $hit = $fuzzphony->in('products')->query('mouse')->highlight('name')->where('price', '<', 4_000)->get()->hits[0];

        self::assertStringContainsString('<mark>Mouse</mark>', $hit->highlights['name']);
        self::assertStringNotContainsString('<b', $hit->highlights['name']);
    }

    public function testDoctorIsHappyAfterInstall(): void
    {
        $report = $this->fuzzphony('queue')->inspect('products', new InspectOptions(deep: true));

        self::assertSame(CheckStatus::Ok, $report->status(), implode("\n", array_map(static fn(Check $c): string => $c->name . ': ' . $c->message, $report->problems())));
    }

    public function testDoctorFindsDriftMissingIndexesAndDisabledTriggers(): void
    {
        $fuzzphony = $this->fuzzphony('queue');
        $this->connection->execute('DROP INDEX fuzzphony_products_fz');
        $this->connection->execute('ALTER TABLE fuzzphony_products DROP COLUMN f_price');
        $this->connection->execute('ALTER TABLE fz_brand DISABLE TRIGGER fuzzphony_sync_products__fz_brand_upd');

        $problems = [];
        foreach ($fuzzphony->inspect('products')->problems() as $check) {
            $problems[$check->name] = $check;
        }

        self::assertStringContainsString('f_price', $problems['Sidecar columns']->message);
        self::assertStringContainsString('CREATE INDEX CONCURRENTLY', (string) $problems['Index fuzzphony_products_fz']->fix);
        self::assertStringContainsString('ENABLE TRIGGER', (string) $problems['Sync trigger on fz_brand']->fix);

        $fuzzphony->schema()->apply($this->connection); // idempotent repair (except the trigger state, which is deliberate)
        $this->connection->execute('ALTER TABLE fz_brand ENABLE TRIGGER fuzzphony_sync_products__fz_brand_upd');
        $fuzzphony->reindex('products');
        self::assertTrue($fuzzphony->inspect('products')->isHealthy());
    }

    public function testDoctorReportsABrokenSourceMapping(): void
    {
        $fuzzphony = $this->fuzzphony('manual');
        $this->connection->execute('ALTER TABLE fz_product RENAME COLUMN price TO price_huf');

        $messages = array_map(static fn(Check $c): string => $c->message, $fuzzphony->inspect('products')->problems());

        self::assertNotEmpty(array_filter($messages, static fn(string $m): bool => str_contains($m, 'cannot be queried')));
    }

    public function testDoctorReportsColumnAwareFiltering(): void
    {
        // Create a custom index with explicit columns on a watch to test column-aware filtering
        $index = Indexes::products('queue')
            ->with(watches: [
                new \Fuzzphony\Core\Definition\Watch('fz_product', columns: ['name', 'price']),
                new \Fuzzphony\Core\Definition\Watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', 'id', ['name']),
            ]);

        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');

        $messages = array_column($fuzzphony->inspect('products')->checks, 'message', 'name');

        self::assertArrayHasKey('Column-aware filtering', $messages);
    }

    /**
     * A typo'd explicit column name (e.g. "nmae" instead of "name") passes
     * DefinitionValidator (identifier syntax only) and `--apply` (PL/pgSQL resolves column
     * names lazily), then breaks every UPDATE on the watched table at runtime. The doctor
     * must report this as an error, not as "active".
     */
    public function testDoctorReportsAnErrorForAWatchColumnThatDoesNotExist(): void
    {
        $index = Indexes::products('queue')
            ->with(watches: [
                new \Fuzzphony\Core\Definition\Watch('fz_product'),
                new \Fuzzphony\Core\Definition\Watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', 'id', ['nmae']),
            ]);

        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');

        $checks = array_values(array_filter(
            $fuzzphony->inspect('products')->checks,
            static fn(Check $c): bool => $c->name === 'Column-aware filtering',
        ));

        self::assertNotEmpty($checks, 'expected a "Column-aware filtering" check to be reported');
        foreach ($checks as $check) {
            self::assertSame(CheckStatus::Error, $check->status, $check->message);
        }
    }

    public function testExplainReturnsSqlAndPlan(): void
    {
        $explanation = $this->fuzzphony('manual')->in('products')->query('wireless mouse')->explain(analyze: true);

        self::assertSame('(wireless AND mouse)', $explanation->interpretedAs);
        self::assertNotEmpty($explanation->statements);
        self::assertNotEmpty(array_filter($explanation->plan, static fn(string $l): bool => str_contains($l, 'actual time')));
    }

    public function testErrorsCarryAHint(): void
    {
        $fuzzphony = $this->fuzzphony('manual');
        $this->connection->execute('DROP TABLE fuzzphony_products');

        $this->expectException(EngineFailure::class);
        $this->expectExceptionMessageMatches('/Hint: Run "bin\/console fuzzphony:doctor"/');
        $fuzzphony->in('products')->query('mouse')->get();
    }

    public function testSchemaCanBeDroppedWithoutTouchingTheSource(): void
    {
        $fuzzphony = $this->fuzzphony('queue');
        $this->engine->dropSchema($fuzzphony->registry()->get('products'))->apply($this->connection);

        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"));
        self::assertSame(5, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fz_product')));
        $this->connection->execute("UPDATE fz_brand SET name = 'x' WHERE id = 1"); // no trigger left behind
        $this->addToAssertionCount(1);
    }
}
