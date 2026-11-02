<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Analytics;

use Fuzzphony\Core\Engine\Engine;

/** @internal The log of Fuzzphony::postgres(searchLog: true): each record is written to the engine's table at once. */
final readonly class EngineSearchLog implements SearchLog
{
    public function __construct(private Engine $engine) {}

    public function record(SearchRecord $record): void
    {
        $this->engine->writeSearchLog([$record]);
    }
}
