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
        $labels = self::withoutQuery($labels);
        $this->registry->getOrRegisterCounter('', self::name($event), $event, array_keys($labels))
            ->incBy($by, self::labelValues($labels));
    }

    public function observe(string $event, float $value, array $labels = []): void
    {
        $labels = self::withoutQuery($labels);
        $this->registry->getOrRegisterHistogram('', self::name($event), $event, array_keys($labels))
            ->observe($value, self::labelValues($labels));
    }

    public function gauge(string $event, float $value, array $labels = []): void
    {
        $labels = self::withoutQuery($labels);
        $this->registry->getOrRegisterGauge('', self::name($event), $event, array_keys($labels))
            ->set($value, self::labelValues($labels));
    }

    /** Prometheus metric names must match [a-zA-Z_:][a-zA-Z0-9_:]* — guard()'s own operation names
     * (e.g. "queue processing", "source ids") contain spaces, so more than the dot needs folding. */
    private static function name(string $event): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_:]+/', '_', $event);
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

    /**
     * Raw search text is unbounded cardinality for a Prometheus label (one new time series per
     * distinct query ever run) — never forwarded here, unlike LoggingMetricsCollector's opt-in.
     *
     * @param array<string, scalar> $labels
     *
     * @return array<string, scalar>
     */
    private static function withoutQuery(array $labels): array
    {
        return array_diff_key($labels, ['query' => true]);
    }
}
