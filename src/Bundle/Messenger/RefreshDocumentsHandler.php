<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Messenger;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Observability\MetricsCollector;
use Fuzzphony\Core\Observability\NullMetricsCollector;

/** @internal Messenger handler for RefreshDocuments: reindexes the given ids through Fuzzphony::refresh(). */
final readonly class RefreshDocumentsHandler
{
    public function __construct(
        private Fuzzphony $fuzzphony,
        private MetricsCollector $metrics = new NullMetricsCollector(),
    ) {}

    public function __invoke(RefreshDocuments $message): void
    {
        $started = hrtime(true);
        try {
            // Idempotent: a retried message simply rebuilds the same documents again.
            $this->fuzzphony->refresh($message->index, $message->ids);
            $this->metrics->observe('fuzzphony.messenger.refresh.duration_ms', round((hrtime(true) - $started) / 1e6, 3), ['index' => $message->index]);
        } catch (\Throwable $e) {
            $this->metrics->increment('fuzzphony.messenger.refresh.errors', ['index' => $message->index]);
            throw $e;
        }
    }
}
