<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

use Fuzzphony\Core\Analytics\IpMode;
use Fuzzphony\Core\Analytics\LoggedSearch;
use Fuzzphony\Core\Analytics\NullSearchLog;
use Fuzzphony\Core\Analytics\QueryRedactor;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Analytics\SearchLog;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Exception\RateLimited;
use Fuzzphony\Core\Exception\SearchRefused;
use Fuzzphony\Core\Observability\MetricsCollector;
use Fuzzphony\Core\Observability\NullMetricsCollector;
use Fuzzphony\Core\Query\SearchQuery;
use Psr\Log\LoggerInterface;

/**
 * What every search passes through when Fuzzphony is built with a gate: the refusals are counted and logged
 * here, and every search and refusal is recorded in the search log. A search made as an actor also runs the guards:
 * they may refuse it (AccessDenied, RateLimited) or limit the fields it searches.
 *
 * @internal
 */
final readonly class SearchGate
{
    /** The characters of a text the redactor may see: four times the 255 that are stored. */
    private const int MAX_REDACTED_INPUT = 4 * 255;

    /** What the search log stores as the user of a search made with asSystem() (a real user cannot be told apart by the brackets in practice). */
    public const string SYSTEM_USER = '(system)';

    public const string LOG_OFF = 'The search log is off: a search cannot be recorded (Symfony: fuzzphony.search_log.enabled: true; plain PHP: searchLog: true or a log:).';

    /**
     * @param list<SearchGuard> $guards run, in order, for a search made as an actor; what they allow is intersected
     * @param IpMode            $ipMode whether the search log keeps the client IP of the actor, and how much of it (the default: none)
     * @param ActorResolver|null $actors who searches when the caller named no actor (the application's current user); null = anonymous when there are guards,
     *                                   else nobody (no guard runs, as there is none)
     * @param bool              $allowSystem whether asSystem() is honoured; false refuses it (AccessDenied)
     */
    public function __construct(
        private MetricsCollector $metrics = new NullMetricsCollector(),
        private ?LoggerInterface $logger = null,
        private SearchLog $log = new NullSearchLog(),
        private ?QueryRedactor $redactor = null,
        private array $guards = [],
        private IpMode $ipMode = IpMode::Off,
        private ?ActorResolver $actors = null,
        private bool $allowSystem = true,
    ) {}

    /**
     * The actor of the application right now (the ambient one), or null when the gate has no resolver: a search that names no actor
     * is made as this one, so a controller that forgets asActor() is guarded like one that remembers. No user is the anonymous actor.
     */
    public function ambientActor(): ?Actor
    {
        // guards without a resolver: a search that names no actor is anonymous, never unguarded
        return $this->actors?->resolve() ?? ($this->guards !== [] ? Actor::anonymous() : null);
    }

    /**
     * Whether asSystem() is honoured.
     *
     * @throws AccessDenied
     */
    public function assertSystemAllowed(IndexDefinition $index): void
    {
        if (!$this->allowSystem) {
            throw AccessDenied::systemSwitchedOff($index->name);
        }
    }

    /**
     * The query searched as this actor: a tenant-scoped index takes the actor's tenant (an integer tenant on a string tenant filter is
     * turned into its string; any other pairing is left as it is); an actor without a tenant leaves a tenant set before alone.
     * A tenant the caller named with forTenant() is never replaced: when it differs from the actor's, assertTenantAgrees() refuses
     * the search (one rule for every entry point and every order of the calls). The tenant a previous actor brought is replaced.
     */
    public static function withActor(IndexDefinition $index, SearchQuery $query, Actor $actor): SearchQuery
    {
        $previous = $query->actor;
        $query = $query->withActor($actor);
        if ($index->tenant !== null && $actor->tenant !== null) {
            $derived = $query->tenant === null || ($previous?->tenant !== null && self::sameTenant($query->tenant, self::actorTenant($index, $previous->tenant)));
            if ($derived) {
                $query = $query->forTenant(self::actorTenant($index, $actor->tenant));
            }
        }

        return $query;
    }

    /** The tenant of an actor in the type of the tenant filter (an integer on a string filter becomes its string). */
    private static function actorTenant(IndexDefinition $index, mixed $tenant): mixed
    {
        return is_int($tenant) && $index->tenant !== null && $index->filter($index->tenant)->type === FilterType::String ? (string) $tenant : $tenant;
    }

    private static function sameTenant(mixed $a, mixed $b): bool
    {
        $scalar = static fn(mixed $v): bool => is_scalar($v) || $v instanceof \Stringable;

        return $scalar($a) && $scalar($b) ? (string) $a === (string) $b : $a === $b;
    }

    /**
     * An actor with a tenant never searches another tenant: a tenant named with forTenant() that differs from the actor's refuses the search
     * (asSystem() is the explicit way to cross tenants). The refusal names no tenant.
     *
     * @throws AccessDenied
     */
    public static function assertTenantAgrees(IndexDefinition $index, SearchQuery $query): void
    {
        $actor = $query->system ? null : $query->actor;
        if ($index->tenant !== null && $actor?->tenant !== null && $query->tenant !== null && !self::sameTenant($query->tenant, self::actorTenant($index, $actor->tenant))) {
            throw AccessDenied::tenantMismatch($index->name);
        }
    }

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
     * access() for a caller that has no search to run (the completions of Fuzzphony::complete()): a refusal is counted,
     * logged and, with $typed, recorded in the search log like the one of a search.
     *
     * @param bool        $charge as in access()
     * @param string|null $typed  the text the visitor typed: given, a refusal writes its row to the search log
     *
     * @throws AccessDenied|RateLimited
     */
    public function accessCounted(IndexDefinition $index, Actor $actor, bool $charge = true, ?string $typed = null): Access
    {
        try {
            return $this->access($index, $actor, $charge);
        } catch (AccessDenied|RateLimited $e) {
            $typed === null ? $this->countRefusal($index, $e) : $this->refused($index, new SearchQuery(actor: $actor), $e, $typed);

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
        if (($query->actor !== null || $query->system) && $index->tenant !== null && $query->tenant === null) {
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
            if ($query->system) {
                $this->assertSystemAllowed($index);
            } elseif ($query->actor === null && ($ambient = $this->ambientActor()) !== null) {
                $query = self::withActor($index, $query, $ambient);
            }
            $asked = $query; // a refusal is recorded with the actor that was asked
            self::assertActorHasTenant($index, $query);
            self::assertTenantAgrees($index, $query);
            // a system search runs no guard; any other search does, as the ambient actor when the caller named none
            if ($query->actor !== null && $this->guards !== []) {
                $access = $this->access($index, $query->actor, $charge);
                if ($access->fields !== null) {
                    $allowed = $query->allowedFields === null ? $access->fields : array_values(array_intersect($query->allowedFields, $access->fields));
                    $query = $query->restrictedTo($allowed);
                }
            }
        } catch (SearchRefused $e) {
            $this->refused($index, $asked, $e, $typed);

            throw $e;
        }

        $result = $run($query);
        if ($result instanceof SearchResult && $query->log) {
            // explain() runs the search again for a developer and records nothing: only search() passes a SearchResult;
            // an unlogged search records no ok row (a refusal is always recorded)
            $this->record($index, $query, $typed, $result->total, $result->tookMs, $result->usedFuzzy, RecordStatus::Ok, $result->correctedFrom !== null);
        }

        return $result;
    }

    private function recordOf(IndexDefinition $index, SearchQuery $query, ?string $typed, int $hits, float $tookMs, bool $usedFuzzy, RecordStatus $status, bool $rescued): SearchRecord
    {
        // bound the input first (the redaction patterns must not run over a text of any size), then redact, then cut:
        // a pattern is never cut in half by the final cut, and the bound is far above it (4 x the 255 that is kept)
        $text = mb_substr($typed ?? $query->text, 0, self::MAX_REDACTED_INPUT);
        $text = $this->redactor?->redact($text) ?? $text;

        return new SearchRecord(new \DateTimeImmutable(), $index->name, self::tenantOf($index, $query), $query->system ? self::SYSTEM_USER : $query->actor?->user, mb_substr($text, 0, 255), $hits, $tookMs, $usedFuzzy, $status, $this->ipMode->apply($query->actor?->ip), $rescued);
    }

    /**
     * Records a search that ran elsewhere, without running it (Fuzzphony::logSearch()): the same actor, redaction, IP mode and tenant rules as
     * a search of this index, no guard (nothing is searched) and no engine call. The index must log and the log must be on.
     *
     * @throws InvalidArgument when the index is not logged or the search log is off
     * @throws ActorRequired   on a tenant-scoped index without a tenant
     * @throws AccessDenied    asSystem() while it is switched off, or a tenant that is not the actor's
     */
    public function logSearch(IndexDefinition $index, SearchQuery $query, int $hits, float $tookMs, bool $usedFuzzy, bool $rescued): LoggedSearch
    {
        if (!$index->log) {
            throw new InvalidArgument(sprintf('Index "%s" is not logged (log: false), so a search cannot be recorded for it.', $index->name));
        }
        if ($this->log instanceof NullSearchLog) {
            throw new InvalidArgument(self::LOG_OFF);
        }
        if ($query->system) {
            $this->assertSystemAllowed($index);
        } elseif ($query->actor === null && ($ambient = $this->ambientActor()) !== null) {
            $query = self::withActor($index, $query, $ambient);
        }
        self::assertTenantAgrees($index, $query);
        if ($index->tenant !== null && $query->tenant === null) {
            throw ActorRequired::forIndex($index->name); // a tenant must be known, with or without an actor
        }
        $record = $this->recordOf($index, $query, null, max(0, $hits), max(0.0, $tookMs), $usedFuzzy, RecordStatus::Ok, $rescued);
        try {
            $this->log->record($record);
        } catch (\Throwable $e) {
            $this->metrics->increment('fuzzphony.search_log.failed', ['index' => $index->name]);
            $this->logger?->error('Fuzzphony could not record a search: {message}', ['message' => $e->getMessage(), 'index' => $index->name]);
        }

        return new LoggedSearch($record->at, $record->index, $record->tenant === null ? null : (string) $record->tenant, $record->user, $record->ip, $record->query, $record->hits, $record->tookMs, $record->usedFuzzy, $record->status, $record->rescued);
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
    private static function tenantOf(IndexDefinition $index, SearchQuery $query): int|string|null
    {
        $tenant = $query->tenant ?? $query->actor?->tenant;
        if (!$query->system && $index->tenant !== null && $query->actor?->tenant !== null && !self::sameTenant($tenant, self::actorTenant($index, $query->actor->tenant))) {
            $tenant = $query->actor->tenant; // a refused crossing is recorded under the actor's own tenant, never the one it asked for
        }

        return match (true) {
            $tenant === null, is_int($tenant), is_string($tenant) => $tenant,
            is_scalar($tenant), $tenant instanceof \Stringable => (string) $tenant,
            default => null,
        };
    }

    /** A log that fails (or a redactor that does) must never fail the search: it is counted and logged instead. */
    private function record(IndexDefinition $index, SearchQuery $query, ?string $typed, int $hits, float $tookMs, bool $usedFuzzy, RecordStatus $status, bool $rescued = false): void
    {
        if (!$index->log || $this->log instanceof NullSearchLog) {
            return;
        }

        try {
            $this->log->record($this->recordOf($index, $query, $typed, $hits, $tookMs, $usedFuzzy, $status, $rescued));
        } catch (\Throwable $e) {
            $this->metrics->increment('fuzzphony.search_log.failed', ['index' => $index->name]);
            $this->logger?->error('Fuzzphony could not record a search: {message}', ['message' => $e->getMessage(), 'index' => $index->name]);
        }
    }
}
