<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Observability;

use Psr\Log\LoggerInterface;

/** The always-available default: one structured log line per call (event, by/value, the labels). */
final readonly class LoggingMetricsCollector implements MetricsCollector
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function increment(string $event, array $labels = [], int $by = 1): void
    {
        $this->logger->debug('fuzzphony.metric', ['event' => $event, 'by' => $by, ...$labels]);
    }

    public function observe(string $event, float $value, array $labels = []): void
    {
        $this->logger->debug('fuzzphony.metric', ['event' => $event, 'value' => $value, ...$labels]);
    }

    public function gauge(string $event, float $value, array $labels = []): void
    {
        $this->logger->debug('fuzzphony.metric', ['event' => $event, 'value' => $value, ...$labels]);
    }
}
