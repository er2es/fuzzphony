<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\EventListener;

use Fuzzphony\Bundle\Analytics\BufferedSearchLog;

/** @internal Writes the buffered search log after the response (kernel.terminate) or the command (console.terminate). */
final readonly class SearchLogFlushListener
{
    public function __construct(private BufferedSearchLog $log) {}

    public function __invoke(object $event): void
    {
        $this->log->flush();
    }
}
