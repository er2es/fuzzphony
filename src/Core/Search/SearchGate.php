<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

use Fuzzphony\Core\Analytics\NullSearchLog;
use Fuzzphony\Core\Analytics\QueryRedactor;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Analytics\SearchLog;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Observability\MetricsCollector;
use Fuzzphony\Core\Observability\NullMetricsCollector;
use Fuzzphony\Core\Query\SearchQuery;
use Psr\Log\LoggerInterface;

/**
 * What every search passes through when Fuzzphony is built with a gate: the refusals are counted and logged
 * here, and every search and refusal is recorded in the search log. The guards (0.9, part 3) hook in here too.
 */
final readonly class SearchGate
{
    public function __construct(
        private MetricsCollector $metrics = new NullMetricsCollector(),
        private ?LoggerInterface $logger = null,
        private SearchLog $log = new NullSearchLog(),
        private ?QueryRedactor $redactor = null,
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

            $this->record($index, $query, 0, 0.0, false, RecordStatus::Denied);

            throw $e;
        }

        $result = $run();
        if ($result instanceof SearchResult) {
            // explain() runs the search again for a developer and records nothing: only search() passes a SearchResult
            $this->record($index, $query, $result->total, $result->tookMs, $result->usedFuzzy, RecordStatus::Ok);
        }

        return $result;
    }

    /**
     * The tenant the search ran for, in the shape the log stores: the effective one of the query, else (an index
     * without tenant scoping) the tenant of the actor who asked.
     */
    private static function tenantOf(SearchQuery $query): int|string|null
    {
        $tenant = $query->tenant ?? $query->actor?->tenant;

        return match (true) {
            $tenant === null, is_int($tenant), is_string($tenant) => $tenant,
            is_scalar($tenant), $tenant instanceof \Stringable => (string) $tenant,
            default => null,
        };
    }

    /** A log that fails (or a redactor that does) must never fail the search: it is counted and logged instead. */
    private function record(IndexDefinition $index, SearchQuery $query, int $hits, float $tookMs, bool $usedFuzzy, RecordStatus $status): void
    {
        if (!$index->log || $this->log instanceof NullSearchLog) {
            return;
        }

        try {
            // redact first, cut after: a pattern is never cut in half
            $text = $this->redactor?->redact($query->text) ?? $query->text;
            $this->log->record(new SearchRecord(new \DateTimeImmutable(), $index->name, self::tenantOf($query), $query->actor?->user, mb_substr($text, 0, 255), $hits, $tookMs, $usedFuzzy, $status));
        } catch (\Throwable $e) {
            $this->metrics->increment('fuzzphony.search_log.failed', ['index' => $index->name]);
            $this->logger?->error('Fuzzphony could not record a search: {message}', ['message' => $e->getMessage(), 'index' => $index->name]);
        }
    }
}
