<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Observability;

use Psr\Log\LoggerInterface;

/**
 * The always-available default: one structured log line per call. `$logQueryText` (default false)
 * keeps a `query` label on the logged line; it is never forwarded to a Prometheus label
 * (unbounded cardinality — see the spec).
 */
final readonly class LoggingMetricsCollector implements MetricsCollector
{
    public function __construct(
        private LoggerInterface $logger,
        private bool $logQueryText = false,
    ) {}

    public function increment(string $event, array $labels = [], int $by = 1): void
    {
        $this->logger->debug('fuzzphony.metric', ['event' => $event, 'by' => $by, ...$this->filter($labels)]);
    }

    public function observe(string $event, float $value, array $labels = []): void
    {
        $this->logger->debug('fuzzphony.metric', ['event' => $event, 'value' => $value, ...$this->filter($labels)]);
    }

    public function gauge(string $event, float $value, array $labels = []): void
    {
        $this->logger->debug('fuzzphony.metric', ['event' => $event, 'value' => $value, ...$this->filter($labels)]);
    }

    /**
     * @param array<string, scalar> $labels
     *
     * @return array<string, scalar>
     */
    private function filter(array $labels): array
    {
        return $this->logQueryText ? $labels : array_diff_key($labels, ['query' => true]);
    }
}
