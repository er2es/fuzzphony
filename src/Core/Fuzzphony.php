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

    public function reindex(string $index, int $batchSize = 5_000, ?callable $onBatch = null): int
    {
        return (new Reindexer($this->engine))->run($this->registry->get($index), $batchSize, null, $onBatch);
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
