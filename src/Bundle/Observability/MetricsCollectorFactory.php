<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Observability;

use Fuzzphony\Core\Observability\LoggingMetricsCollector;
use Fuzzphony\Core\Observability\MetricsCollector;
use Psr\Log\LoggerInterface;

/**
 * @internal Decides which MetricsCollector to use when the service is actually built, not when the
 * container is compiled — CLI and FPM commonly disagree about apcu (apc.enable_cli=0 by default),
 * and a compiled container's service definitions are shared between them: baking this decision in
 * at loadExtension() time means whichever process warms the cache first decides it for both.
 */
final class MetricsCollectorFactory
{
    /**
     * @param (\Closure(): \Prometheus\CollectorRegistry)|null $registry overrides how the Prometheus
     *        registry is built (default: APCu-backed) — a test seam, so the branch itself is
     *        testable without requiring a real apcu extension.
     */
    public static function create(LoggerInterface $logger, ?bool $prometheusAvailable = null, ?\Closure $registry = null): MetricsCollector
    {
        $available = $prometheusAvailable ?? (class_exists(\Prometheus\CollectorRegistry::class) && \extension_loaded('apcu') && \apcu_enabled());
        if ($available) {
            $registry ??= static fn(): \Prometheus\CollectorRegistry => new \Prometheus\CollectorRegistry(new \Prometheus\Storage\APCng());

            return new PrometheusMetricsCollector($registry());
        }

        return new LoggingMetricsCollector($logger);
    }
}
