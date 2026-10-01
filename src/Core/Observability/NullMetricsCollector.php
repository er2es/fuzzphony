<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Observability;

/** The library-level default: zero cost, zero behavior, for an application that wires nothing. */
final class NullMetricsCollector implements MetricsCollector
{
    public function increment(string $event, array $labels = [], int $by = 1): void {}

    public function observe(string $event, float $value, array $labels = []): void {}

    public function gauge(string $event, float $value, array $labels = []): void {}
}
