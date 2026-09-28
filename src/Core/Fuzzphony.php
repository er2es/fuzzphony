<?php

declare(strict_types=1);

namespace Fuzzphony\Core;

use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Inspection\InspectionReport;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Sync\Reindexer;
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Core\Sync\ReindexResult;

/** The one service applications talk to. */
final readonly class Fuzzphony
{
    public function __construct(
        private Engine $engine,
        private IndexRegistry $registry,
    ) {}

    /** @param string|class-string $indexOrEntityClass */
    public function in(string $indexOrEntityClass): SearchBuilder
    {
        return new SearchBuilder($this->engine, $this->registry->get($indexOrEntityClass));
    }

    public function schema(?string $index = null): SchemaPlan
    {
        $indexes = $index === null ? array_values($this->registry->all()) : [$this->registry->get($index)];
        $plan = $this->engine->globalSchema(...$indexes);
        foreach ($indexes as $definition) {
            $plan = $plan->merge($this->engine->indexSchema($definition));
        }

        return $plan;
    }

    public function inspect(string $index, InspectOptions $options = new InspectOptions()): InspectionReport
    {
        return $this->engine->inspect($this->registry->get($index), $options);
    }

    /** @param list<int|string> $ids */
    public function refresh(string $index, array $ids): int
    {
        return $this->engine->refresh($this->registry->get($index), $ids);
    }

    /**
     * Rebuilds the whole index from the source, next to the live one, and swaps it in when it is
     * complete; the documents the source no longer returns go with the old index. See
     * ReindexOptions for writing in place, resuming and pruning.
     *
     * Call it outside a transaction: the run commits batch by batch and swaps in a short
     * transaction of its own. Inside a caller's transaction every batch and the swap's ACCESS
     * EXCLUSIVE lock would join it (searches blocked until the caller commits), so the engine
     * writes the live index in place there, as before 0.5 (ReindexResult::$swapped is false).
     */
    public function reindex(string $index, ReindexOptions $options = new ReindexOptions()): ReindexResult
    {
        return (new Reindexer($this->engine))->run($this->registry->get($index), $options);
    }

    public function registry(): IndexRegistry
    {
        return $this->registry;
    }

    public function engine(): Engine
    {
        return $this->engine;
    }
}
