<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Observability;

use Fuzzphony\Core\Observability\MetricsCollector;
use Prometheus\CollectorRegistry;

/** @internal Translates Fuzzphony's metric calls into promphp's CollectorRegistry API. */
final readonly class PrometheusMetricsCollector implements MetricsCollector
{
    public function __construct(private CollectorRegistry $registry) {}

    public function increment(string $event, array $labels = [], int $by = 1): void
    {
        $this->registry->getOrRegisterCounter('', self::name($event), $event, array_keys($labels))
            ->incBy($by, self::labelValues($labels));
    }

    public function observe(string $event, float $value, array $labels = []): void
    {
        $this->registry->getOrRegisterHistogram('', self::name($event), $event, array_keys($labels))
            ->observe($value, self::labelValues($labels));
    }

    public function gauge(string $event, float $value, array $labels = []): void
    {
        $this->registry->getOrRegisterGauge('', self::name($event), $event, array_keys($labels))
            ->set($value, self::labelValues($labels));
    }

    private static function name(string $event): string
    {
        return str_replace('.', '_', $event);
    }

    /**
     * @param array<string, scalar> $labels
     *
     * @return list<string>
     */
    private static function labelValues(array $labels): array
    {
        return array_map(static fn(bool|float|int|string $v): string => (string) $v, array_values($labels));
    }
}
