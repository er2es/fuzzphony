<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Observability\MetricsCollector;
use Fuzzphony\Core\Observability\NullMetricsCollector;
use Fuzzphony\Core\Query\SearchQuery;
use Psr\Log\LoggerInterface;

/**
 * @internal What every search passes through when the bundle (or an application) builds Fuzzphony with a gate:
 * the refusals are counted and logged here. The search log and the guards (0.9, parts 2 and 3) hook in here too.
 */
final readonly class SearchGate
{
    public function __construct(
        private MetricsCollector $metrics = new NullMetricsCollector(),
        private ?LoggerInterface $logger = null,
    ) {}

    public function search(Engine $engine, IndexDefinition $index, SearchQuery $query): SearchResult
    {
        return $this->guarded($index, $query, static fn(): SearchResult => $engine->search($index, $query));
    }

    public function explain(Engine $engine, IndexDefinition $index, SearchQuery $query, bool $analyze): Explanation
    {
        return $this->guarded($index, $query, static fn(): Explanation => $engine->explain($index, $query, $analyze));
    }

    /**
     * A search made as an actor must have a tenant on a tenant-scoped index. A search without an actor keeps
     * the engine's own check (and message), so nothing changes for code that does not use actors.
     *
     * @throws ActorRequired
     */
    public static function assertActorHasTenant(IndexDefinition $index, SearchQuery $query): void
    {
        if ($query->actor !== null && $index->tenant !== null && $query->tenant === null) {
            throw ActorRequired::forIndex($index->name);
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $run
     *
     * @return T
     */
    private function guarded(IndexDefinition $index, SearchQuery $query, \Closure $run): mixed
    {
        try {
            self::assertActorHasTenant($index, $query);
        } catch (ActorRequired $e) {
            $this->metrics->increment('fuzzphony.search.denied', ['index' => $index->name, 'reason' => 'actor_required']);
            $this->logger?->warning('Fuzzphony refused a search: {message}', ['message' => $e->getMessage(), 'index' => $index->name]);

            throw $e;
        }

        return $run();
    }
}
