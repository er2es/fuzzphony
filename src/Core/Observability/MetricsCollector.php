<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Observability;

/**
 * Three shapes, matching Prometheus's own data model: a running total (increment), a
 * duration/value distribution (observe) and a point-in-time value (gauge). Implementations must
 * never throw for any input a Fuzzphony-internal call site passes (a misbehaving backend should be
 * fixed, not silently caught — see docs/superpowers/specs/2026-10-01-v06-events-design.md).
 */
interface MetricsCollector
{
    /** @param array<string, scalar> $labels */
    public function increment(string $event, array $labels = [], int $by = 1): void;

    /** @param array<string, scalar> $labels */
    public function observe(string $event, float $value, array $labels = []): void;

    /** @param array<string, scalar> $labels */
    public function gauge(string $event, float $value, array $labels = []): void;
}
