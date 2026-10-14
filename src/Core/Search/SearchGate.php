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
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\RateLimited;
use Fuzzphony\Core\Observability\MetricsCollector;
use Fuzzphony\Core\Observability\NullMetricsCollector;
use Fuzzphony\Core\Query\SearchQuery;
use Psr\Log\LoggerInterface;

/**
 * What every search passes through when Fuzzphony is built with a gate: the refusals are counted and logged
 * here, and every search and refusal is recorded in the search log. A search made as an actor also runs the guards:
 * they may refuse it (AccessDenied, RateLimited) or limit the fields it searches.
 */
final readonly class SearchGate
{
    /** The characters of a text the redactor may see: four times the 255 that are stored. */
    private const int MAX_REDACTED_INPUT = 4 * 255;

    /** @param list<SearchGuard> $guards run, in order, for a search made as an actor; what they allow is intersected */
    public function __construct(
        private MetricsCollector $metrics = new NullMetricsCollector(),
        private ?LoggerInterface $logger = null,
        private SearchLog $log = new NullSearchLog(),
        private ?QueryRedactor $redactor = null,
        private array $guards = [],
    ) {}

    /**
     * @param string|null $typed  what the visitor typed, when $query carries a rewrite of it (search-as-you-type adds a
     *                            prefix alternative): the search log stores this text, not the engine's version
     * @param bool        $charge false does not consume a rate-limit token (the later indexes of a federated search)
     *
     * @throws AccessDenied|ActorRequired|RateLimited
     */
    public function search(Engine $engine, IndexDefinition $index, SearchQuery $query, ?string $typed = null, bool $charge = true): SearchResult
    {
        return $this->guarded($index, $query, static fn(SearchQuery $q): SearchResult => $engine->search($index, $q), $typed, $charge);
    }

    /**
     * @param string|null $typed  as in search(): a refused explain() is recorded with the text the visitor typed
     * @param bool        $charge as in search()
     *
     * @throws AccessDenied|ActorRequired|RateLimited
     */
    public function explain(Engine $engine, IndexDefinition $index, SearchQuery $query, bool $analyze, ?string $typed = null, bool $charge = true): Explanation
    {
        return $this->guarded($index, $query, static fn(SearchQuery $q): Explanation => $engine->explain($index, $q, $analyze), $typed, $charge);
    }

    /**
     * What the guards let this actor see of the index: the intersection of every guard, in the order of the guards (an
     * authorization guard placed first refuses before a rate limit is charged). Names that are no field of the index
     * are dropped; Access::only([]) stays "no field" (the search is restricted to the empty list, it fails closed). A
     * viewer who may see every field of the index gets Access::all() (the unrestricted path).
     *
     * @throws AccessDenied|RateLimited
     */
    public function access(IndexDefinition $index, Actor $actor, bool $charge = true): Access
    {
        $access = Access::all();
        foreach ($this->guards as $guard) {
            $access = $access->intersect($guard->check($index, $actor, $charge));
        }
        if ($access->fields === null) {
            return $access;
        }
        $known = $index->fieldNames();

        return array_diff($known, $access->fields) === [] ? Access::all() : Access::only(array_values(array_intersect($access->fields, $known)));
    }

    /**
     * access() for a caller that has no search to run (the completions of Fuzzphony::suggest()): a refusal is counted
     * and logged like the one of a search, but no row is written to the search log (there is no query text).
     *
     * @param bool $charge as in access()
     *
     * @throws AccessDenied|RateLimited
     */
    public function accessCounted(IndexDefinition $index, Actor $actor, bool $charge = true): Access
    {
        try {
            return $this->access($index, $actor, $charge);
        } catch (AccessDenied|RateLimited $e) {
            $this->countRefusal($index, $e);

            throw $e;
        }
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
     * @param \Closure(SearchQuery): T $run receives the query the engine is asked: restricted to the fields the guards allow
     *
     * @return T
     */
    private function guarded(IndexDefinition $index, SearchQuery $query, \Closure $run, ?string $typed, bool $charge): mixed
    {
        $asked = $query;

        try {
            self::assertActorHasTenant($index, $query);
            // a search without an actor never runs the guards
            if ($query->actor !== null && $this->guards !== []) {
                $access = $this->access($index, $query->actor, $charge);
                if ($access->fields !== null) {
                    $allowed = $query->allowedFields === null ? $access->fields : array_values(array_intersect($query->allowedFields, $access->fields));
                    $query = $query->restrictedTo($allowed);
                }
            }
        } catch (ActorRequired|AccessDenied|RateLimited $e) {
            $this->refused($index, $asked, $e, $typed);

            throw $e;
        }

        $result = $run($query);
        if ($result instanceof SearchResult && $query->log) {
            // explain() runs the search again for a developer and records nothing: only search() passes a SearchResult;
            // an unlogged search records no ok row (a refusal is always recorded)
            $this->record($index, $query, $typed, $result->total, $result->tookMs, $result->usedFuzzy, RecordStatus::Ok);
        }

        return $result;
    }

    /** A refused search is counted, logged and recorded (also when it is unlogged): a refusal is never silent. */
    private function refused(IndexDefinition $index, SearchQuery $query, \Throwable $e, ?string $typed): void
    {
        $this->countRefusal($index, $e);

        $this->record($index, $query, $typed, 0, 0.0, false, $e instanceof RateLimited ? RecordStatus::RateLimited : RecordStatus::Denied);
    }

    private function countRefusal(IndexDefinition $index, \Throwable $e): void
    {
        [$event, $labels] = match (true) {
            $e instanceof RateLimited => ['fuzzphony.search.rate_limited', ['index' => $index->name]],
            $e instanceof AccessDenied => ['fuzzphony.search.denied', ['index' => $index->name, 'reason' => 'authorization']],
            default => ['fuzzphony.search.denied', ['index' => $index->name, 'reason' => 'actor_required']],
        };
        $this->metrics->increment($event, $labels);
        $this->logger?->warning('Fuzzphony refused a search: {message}', ['message' => $e->getMessage(), 'index' => $index->name]);
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
    private function record(IndexDefinition $index, SearchQuery $query, ?string $typed, int $hits, float $tookMs, bool $usedFuzzy, RecordStatus $status): void
    {
        if (!$index->log || $this->log instanceof NullSearchLog) {
            return;
        }

        try {
            // bound the input first (the redaction patterns must not run over a text of any size), then redact, then cut:
            // a pattern is never cut in half by the final cut, and the bound is far above it (4 x the 255 that is kept)
            $text = mb_substr($typed ?? $query->text, 0, self::MAX_REDACTED_INPUT);
            $text = $this->redactor?->redact($text) ?? $text;
            $this->log->record(new SearchRecord(new \DateTimeImmutable(), $index->name, self::tenantOf($query), $query->actor?->user, mb_substr($text, 0, 255), $hits, $tookMs, $usedFuzzy, $status));
        } catch (\Throwable $e) {
            $this->metrics->increment('fuzzphony.search_log.failed', ['index' => $index->name]);
            $this->logger?->error('Fuzzphony could not record a search: {message}', ['message' => $e->getMessage(), 'index' => $index->name]);
        }
    }
}
