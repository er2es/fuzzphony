<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Observability;

use Fuzzphony\Bundle\Observability\MetricsCollectorFactory;
use Fuzzphony\Bundle\Observability\PrometheusMetricsCollector;
use Fuzzphony\Core\Observability\LoggingMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Psr\Log\NullLogger;

/**
 * The decision (promphp installed AND apcu loaded+enabled) is made here, at the moment the service
 * is actually built — not baked into the compiled container at loadExtension() time, which would
 * be wrong the moment CLI and FPM (which commonly disagree about apcu, e.g. apc.enable_cli=0 by
 * default) share one compiled cache. $prometheusAvailable lets tests force either branch
 * deterministically; production code always leaves it null (the real environment check).
 */
final class MetricsCollectorFactoryTest extends TestCase
{
    public function testFallsBackToLoggingWhenPrometheusIsNotAvailable(): void
    {
        $collector = MetricsCollectorFactory::create(new NullLogger(), prometheusAvailable: false);

        self::assertInstanceOf(LoggingMetricsCollector::class, $collector);
    }

    public function testUsesPrometheusWhenAvailable(): void
    {
        // The registry override is a test seam: it proves the branch selection without requiring
        // a real apcu extension to construct the production APCu-backed registry.
        $collector = MetricsCollectorFactory::create(
            new NullLogger(),
            prometheusAvailable: true,
            registry: static fn(): CollectorRegistry => new CollectorRegistry(new InMemory(), false),
        );

        self::assertInstanceOf(PrometheusMetricsCollector::class, $collector);
    }

    public function testTheDefaultRegistryIsApcuBackedWhenPrometheusIsAvailable(): void
    {
        // No override: exercises the real default branch (new APCng()). Its constructor throws
        // when apcu isn't loaded/enabled — assert whichever this host actually does, so the test
        // isn't tied to one environment's apcu availability.
        if (\extension_loaded('apcu') && \apcu_enabled()) {
            $collector = MetricsCollectorFactory::create(new NullLogger(), prometheusAvailable: true);
            self::assertInstanceOf(PrometheusMetricsCollector::class, $collector);

            return;
        }

        $this->expectException(\Prometheus\Exception\StorageException::class);
        MetricsCollectorFactory::create(new NullLogger(), prometheusAvailable: true);
    }

    public function testTheRealEnvironmentCheckRunsWhenNotOverridden(): void
    {
        // Asserting this also proves create() doesn't require the override to be passed; which
        // class it picks depends on whether apcu happens to be loaded+enabled on whatever host
        // runs this test, so assert whichever branch the real environment actually takes.
        $collector = MetricsCollectorFactory::create(new NullLogger());

        $expected = class_exists(\Prometheus\CollectorRegistry::class) && \extension_loaded('apcu') && \apcu_enabled()
            ? PrometheusMetricsCollector::class
            : LoggingMetricsCollector::class;
        self::assertInstanceOf($expected, $collector);
    }
}
