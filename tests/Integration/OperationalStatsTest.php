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
use Fuzzphony\Tests\Unit\Core\Observability\RecordingMetricsCollector;
use PHPUnit\Framework\TestCase;

/** The gauges for the shared change log and the queue: size, age, quarantined rows, failing items. */
final class OperationalStatsTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->engine = new PostgresEngine($this->connection);
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([Indexes::products('queue')]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');
    }

    public function testAnIdleIndexReportsZeroes(): void
    {
        self::assertSame([
            'fuzzphony.queue.failing_items' => 0.0,
            'fuzzphony.queue.oldest_age_seconds' => 0.0,
            'fuzzphony.watch_log.entries' => 0.0,
            'fuzzphony.watch_log.oldest_age_seconds' => 0.0,
            'fuzzphony.watch.bad_rows' => 0.0,
        ], $this->engine->operationalStats(Indexes::products('queue')));
    }

    public function testUndrainedLogEntriesAndTheirAgeAreCounted(): void
    {
        $this->connection->execute("UPDATE fz_product SET name = name || ' x' WHERE id = 1");
        $this->connection->execute("UPDATE fuzzphony_watch_log SET at = now() - interval '120 seconds'");

        $stats = $this->engine->operationalStats(Indexes::products('queue'));

        self::assertGreaterThan(0.0, $stats['fuzzphony.watch_log.entries']);
        self::assertSame((float) Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_watch_log')), $stats['fuzzphony.watch_log.entries']);
        self::assertGreaterThanOrEqual(120.0, $stats['fuzzphony.watch_log.oldest_age_seconds']);
    }

    public function testQuarantinedRowsAndFailingQueueItemsAreCounted(): void
    {
        $this->connection->execute("UPDATE fuzzphony_watches SET bad_rows = 3 WHERE index_name = 'products' AND watch_no = 0");
        $this->connection->execute("INSERT INTO fuzzphony_queue (index_name, doc_id, queued_at, attempts) VALUES ('products', '1', now() - interval '90 seconds', 2), ('products', '2', now(), 0), ('products', '*', now(), 5)");

        $stats = $this->engine->operationalStats(Indexes::products('queue'));

        self::assertSame(3.0, $stats['fuzzphony.watch.bad_rows']);
        self::assertSame(1.0, $stats['fuzzphony.queue.failing_items'], 'only attempts > 0 on a document, not a rebuild request');
        self::assertGreaterThanOrEqual(90.0, $stats['fuzzphony.queue.oldest_age_seconds']);
    }

    public function testAnOlderSchemaWithoutTheFailureColumnsLeavesTheQueueGaugesOut(): void
    {
        $this->connection->execute('ALTER TABLE fuzzphony_queue DROP COLUMN attempts CASCADE');

        $stats = $this->engine->operationalStats(Indexes::products('queue'));

        self::assertArrayNotHasKey('fuzzphony.queue.failing_items', $stats);
        self::assertSame(0.0, $stats['fuzzphony.watch_log.entries']);
    }

    public function testNoSharedLogTablesLeavesOnlyTheQueueGauges(): void
    {
        $this->connection->execute('DROP TABLE fuzzphony_watch_log, fuzzphony_watches CASCADE');

        $stats = $this->engine->operationalStats(Indexes::products('queue'));

        self::assertSame(['fuzzphony.queue.failing_items', 'fuzzphony.queue.oldest_age_seconds'], array_keys($stats));
    }

    public function testTheWorkerGaugesThemWithTheIndexLabelOnly(): void
    {
        $metrics = new RecordingMetricsCollector();

        (new Worker($this->engine, metrics: $metrics))->runOnce([Indexes::products('queue')]);

        $names = [];
        foreach ($metrics->calls as [$kind, $name, , $labels]) {
            if ($kind === 'gauge') {
                $names[$name] = $labels;
            }
        }
        foreach (['fuzzphony.queue.depth', 'fuzzphony.queue.failing_items', 'fuzzphony.queue.oldest_age_seconds', 'fuzzphony.watch_log.entries', 'fuzzphony.watch_log.oldest_age_seconds', 'fuzzphony.watch.bad_rows'] as $gauge) {
            self::assertSame(['index' => 'products'], $names[$gauge] ?? null, $gauge);
        }
    }
}
