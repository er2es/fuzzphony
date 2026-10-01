<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Observability;

use Fuzzphony\Core\Observability\MetricsCollector;

/** @internal Records every call, in order, for assertion. Never throws. */
final class RecordingMetricsCollector implements MetricsCollector
{
    /** @var list<array{string, string, float, array<string, scalar>}> */
    public array $calls = [];

    public function increment(string $event, array $labels = [], int $by = 1): void
    {
        $this->calls[] = ['increment', $event, (float) $by, $labels];
    }

    public function observe(string $event, float $value, array $labels = []): void
    {
        $this->calls[] = ['observe', $event, $value, $labels];
    }

    public function gauge(string $event, float $value, array $labels = []): void
    {
        $this->calls[] = ['gauge', $event, $value, $labels];
    }
}
