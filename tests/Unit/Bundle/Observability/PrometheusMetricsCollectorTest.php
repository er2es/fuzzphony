<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Observability;

use Fuzzphony\Bundle\Observability\PrometheusMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;

final class PrometheusMetricsCollectorTest extends TestCase
{
    public function testIncrementRegistersAndIncrementsACounter(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        (new PrometheusMetricsCollector($registry))->increment('fuzzphony.search.fallback', ['index' => 'products'], 3);

        $samples = $registry->getMetricFamilySamples();
        self::assertSame('fuzzphony_search_fallback', $samples[0]->getName());
        self::assertSame('3', $samples[0]->getSamples()[0]->getValue());
        self::assertSame(['index'], $samples[0]->getLabelNames());
    }

    public function testObserveRegistersAHistogram(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        (new PrometheusMetricsCollector($registry))->observe('fuzzphony.search.took_ms', 12.4, ['index' => 'products']);

        $samples = $registry->getMetricFamilySamples();
        self::assertSame('fuzzphony_search_took_ms', $samples[0]->getName());
    }

    public function testGaugeRegistersAGauge(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        (new PrometheusMetricsCollector($registry))->gauge('fuzzphony.queue.depth', 5.0, ['index' => 'products']);

        $samples = $registry->getMetricFamilySamples();
        self::assertSame('fuzzphony_queue_depth', $samples[0]->getName());
        self::assertSame('5', $samples[0]->getSamples()[0]->getValue());
    }

    public function testAnEventNameContainingSpacesIsSanitizedToAValidMetricName(): void
    {
        // guard()'s operation names include "queue processing", "source ids", "orphan pruning",
        // "queue size" and "rebuild failure record" — promphp rejects metric names with spaces.
        $registry = new CollectorRegistry(new InMemory(), false);
        (new PrometheusMetricsCollector($registry))->increment('fuzzphony.queue processing.errors');

        $samples = $registry->getMetricFamilySamples();
        self::assertSame('fuzzphony_queue_processing_errors', $samples[0]->getName());
    }

    public function testTheSameEventCanBeObservedTwiceWithTheSameLabelNames(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new PrometheusMetricsCollector($registry);

        $collector->observe('fuzzphony.search.took_ms', 12.4, ['index' => 'products']);
        $collector->observe('fuzzphony.search.took_ms', 8.1, ['index' => 'categories']);

        $this->addToAssertionCount(1); // did not throw
    }
}
