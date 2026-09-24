<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Sync;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;

final readonly class ImmediateRefreshDispatcher implements RefreshDispatcher
{
    public function __construct(private Engine $engine) {}

    public function dispatch(IndexDefinition $index, array $ids): void
    {
        foreach (array_chunk($ids, 1000) as $chunk) {
            $this->engine->refresh($index, $chunk);
        }
    }
}
