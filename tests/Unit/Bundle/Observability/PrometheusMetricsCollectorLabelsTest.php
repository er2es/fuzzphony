<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Observability;

use Fuzzphony\Bundle\Observability\PrometheusMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;

/** The defaults of increment() and how labels (names, values, their types) reach the Prometheus registry. */
final class PrometheusMetricsCollectorLabelsTest extends TestCase
{
    public function testACounterGoesUpByOneUnlessToldOtherwise(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new PrometheusMetricsCollector($registry);

        $collector->increment('fuzzphony.search.fallback', ['index' => 'products']);
        self::assertSame('1', $registry->getMetricFamilySamples()[0]->getSamples()[0]->getValue());

        $collector->increment('fuzzphony.search.fallback', ['index' => 'products']);
        $collector->increment('fuzzphony.search.fallback', ['index' => 'products'], 5);
        self::assertSame('7', $registry->getMetricFamilySamples()[0]->getSamples()[0]->getValue());
    }

    public function testEveryKindOfMetricNamesItsLabelsAndListsTheirValuesAsStrings(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new PrometheusMetricsCollector($registry);
        $labels = ['index' => 'products', 'attempt' => 3, 'ratio' => 0.5, 'fuzzy' => true];

        $collector->increment('fuzzphony.counted', $labels);
        $collector->observe('fuzzphony.observed', 1.5, $labels);
        $collector->gauge('fuzzphony.gauged', 2.0, $labels);

        $families = [];
        foreach ($registry->getMetricFamilySamples() as $family) {
            $families[$family->getName()] = $family;
        }
        self::assertCount(3, $families);
        foreach (['fuzzphony_counted', 'fuzzphony_observed', 'fuzzphony_gauged'] as $name) {
            self::assertArrayHasKey($name, $families);
            self::assertSame(['index', 'attempt', 'ratio', 'fuzzy'], $families[$name]->getLabelNames(), $name);
            $sample = $families[$name]->getSamples()[0];
            self::assertSame(['products', '3', '0.5', '1'], array_slice($sample->getLabelValues(), 0, 4), $name); // a histogram bucket adds its "le"
        }
    }
}
