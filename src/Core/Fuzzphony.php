<?php

declare(strict_types=1);

namespace Fuzzphony\Core;

use Fuzzphony\Core\Analytics\EngineSearchLog;
use Fuzzphony\Core\Analytics\IpMode;
use Fuzzphony\Core\Analytics\LoggedSearch;
use Fuzzphony\Core\Analytics\NullSearchLog;
use Fuzzphony\Core\Analytics\PatternQueryRedactor;
use Fuzzphony\Core\Analytics\SearchAnalytics;
use Fuzzphony\Core\Analytics\SearchLog;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\IndexDefinitionClass;
use Fuzzphony\Core\Definition\IndexSource;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Exception\RateLimited;
use Fuzzphony\Core\Exception\UnknownIndex;
use Fuzzphony\Core\Inspection\InspectionReport;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Observability\MetricsCollector;
use Fuzzphony\Core\Observability\NullMetricsCollector;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Query\SynonymExpander;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\ActorResolver;
use Fuzzphony\Core\Search\FederatedSearch;
use Fuzzphony\Core\Search\FieldRestrictingGuard;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchGuard;
use Fuzzphony\Core\Sync\Reindexer;
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Core\Sync\ReindexResult;
use Fuzzphony\Core\Wizard\Wizard;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Psr\Log\LoggerInterface;

/** The one service applications talk to. */
final readonly class Fuzzphony
{
    /**
     * Builds the service on PostgreSQL without a framework: the connection and the index definitions, nothing else to wire.
     *
     * @param list<IndexDefinition|class-string> $indexes IndexDefinition objects and/or the class names of definition classes (#[AsIndexDefinition] with a build(IndexBuilder): void method; instantiated without arguments)
     * @param string                $schema          the schema of Fuzzphony's own tables
     * @param string                $extensionSchema the schema that holds the pg_trgm and unaccent extensions
     * @param bool                  $searchLog       keep the search log in Fuzzphony's table (the reports of analytics()); each record is written at once
     * @param list<SearchGuard>     $guards          run, in order, for a search made as an actor; what they allow is intersected
     * @param SearchLog|null        $log             your own sink for the search records (instead of the table of $searchLog)
     * @param list<string>          $redact          "email" or a regular expression with delimiters: matches are cut out of the query text before it is logged
     * @param IpMode                $ipMode          whether the log keeps the client IP of the actor, and how much of it
     * @param ActorResolver|null    $actors          who searches when the code names no actor (asActor()): the application's current user. Without it (and with
     *                                               guards) a search that names no actor is anonymous. Every search is guarded as that actor
     * @param bool                  $allowSystem     false refuses asSystem() (AccessDenied)
     *
     * @throws InvalidDefinition an index has a field with `granted` and no guard that can restrict fields (a FieldRestrictingGuard, as the authorization guard is): a granted field is only hidden by such a guard
     */
    public static function postgres(
        Connection $connection,
        array $indexes,
        string $schema = 'public',
        string $extensionSchema = 'public',
        ?MetricsCollector $metrics = null,
        bool $searchLog = false,
        int $searchLogRetentionDays = 90,
        array $guards = [],
        ?SearchLog $log = null,
        array $redact = [],
        IpMode $ipMode = IpMode::Off,
        ?LoggerInterface $logger = null,
        ?ActorResolver $actors = null,
        bool $allowSystem = true,
    ): self {
        $registry = new IndexRegistry();
        foreach ($indexes as $index) {
            if (is_string($index)) {
                $registry->register(IndexDefinitionClass::fromClass($index), 'definition class ' . $index);
            } else {
                $registry->register($index);
            }
        }
        foreach ($registry->all() as $index) {
            if ($index->hasGrantedField() && !array_any($guards, static fn(SearchGuard $guard): bool => $guard instanceof FieldRestrictingGuard)) {
                throw new InvalidDefinition($index->name, ['a field has "granted" but no guard can restrict fields: pass an authorization guard (a FieldRestrictingGuard) in guards: [...] to Fuzzphony::postgres(); a rate limit alone shows the field to every viewer.']);
            }
        }
        $metrics ??= new NullMetricsCollector();
        $engine = new PostgresEngine($connection, $extensionSchema, $schema, $metrics, $searchLog, $searchLogRetentionDays);
        $log ??= $searchLog ? new EngineSearchLog($engine) : null;
        $gate = $guards !== [] || $log !== null || $redact !== [] || $ipMode !== IpMode::Off || $actors !== null || !$allowSystem
            ? new SearchGate($metrics, $logger, $log ?? new NullSearchLog(), $redact === [] ? null : PatternQueryRedactor::fromSettings($redact), $guards, $ipMode, $actors, $allowSystem)
            : null;

        return new self($engine, $registry, $gate);
    }

    /** @internal */
    public function __construct(
        private Engine $engine,
        private IndexRegistry $registry,
        private ?SearchGate $gate = null,
    ) {}

    /** @param string|class-string $indexOrEntityClass */
    public function in(string $indexOrEntityClass): SearchBuilder
    {
        return new SearchBuilder($this->engine, $this->registry->get($indexOrEntityClass), gate: $this->gate);
    }

    /** One query over several indexes with one merged list (reciprocal rank fusion); see FederatedSearch. */
    public function federated(): FederatedSearch
    {
        return new FederatedSearch($this->engine, $this->registry, gate: $this->gate);
    }

    /**
     * Records a search in the search log WITHOUT running it, for a search that was answered elsewhere (a cached page, a front end that
     * searched on its own, a result you merged yourself). The record follows the rules of a search of this index: the actor (named, else the
     * application's current user; asSystem() is "(system)"), the redaction and IP mode of the log, the tenant of a tenant-scoped index (the
     * actor's), the 255 characters of the text. Nothing is searched and no guard runs.
     *
     * @param int  $hits    how many hits the search found (the analytics' "found nothing" report counts the records with none)
     * @param bool $rescued the search was answered by the zero-result rescue (SearchResult::$correctedFrom)
     * @param bool $system  as SearchBuilder::asSystem()
     * @param mixed $forTenant as SearchBuilder::forTenant(): the tenant of a system record on a tenant-scoped index; for an actor it must be the actor's
     *
     * @return LoggedSearch what was recorded (the stored, redacted text)
     *
     * @throws InvalidArgument when the index is not logged (`log: false`) or the search log is off: the same conditions under which a
     *                         search is not recorded, here refused so that a call that records nothing is not silent
     * @throws ActorRequired   a tenant-scoped index needs the tenant of the actor
     * @throws AccessDenied    asSystem() while `authorization.allow_system` is false, or a tenant that is not the actor's
     */
    public function logSearch(string $index, string $text, int $hits, float $tookMs = 0.0, ?Actor $actor = null, bool $usedFuzzy = false, bool $rescued = false, bool $system = false, mixed $forTenant = null): LoggedSearch
    {
        $definition = $this->registry->get($index);
        if ($this->gate === null) {
            throw new InvalidArgument(SearchGate::LOG_OFF);
        }
        $query = new SearchQuery(text: $text);
        if ($forTenant !== null) {
            $query = $query->forTenant($forTenant); // as SearchBuilder::forTenant(): needed by asSystem(), and checked against the actor's tenant
        }
        $query = $system ? $query->asSystem() : ($actor === null ? $query : SearchGate::withActor($definition, $query, $actor));

        return $this->gate->logSearch($definition, $query, $hits, $tookMs, $usedFuzzy, $rescued);
    }

    /**
     * Forgets what the engine remembered for the life of the process (the words a text search configuration reduces to nothing, the stems
     * of the synonyms, the vocabulary tables found readable): call it in a long-running process after a text search configuration or a
     * dictionary changed, or after `fuzzphony:schema --apply` created the open-class indexes; a PHP-FPM request starts clean.
     */
    public function resetCaches(): void
    {
        $this->engine->resetCaches();
    }

    /** The configuration wizard over the database of the connection. */
    public function wizard(): Wizard
    {
        return new Wizard($this->engine->introspector());
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
        $definition = $this->registry->get($index);
        if ($options->source !== null) {
            if (!$definition->isMultiSource() || $definition->sourceNamed($options->source) === null) {
                throw new InvalidArgument(sprintf(
                    $definition->isMultiSource() ? 'Index "%s" has no source "%s". Its sources: %s.' : 'Index "%s" has a single source, so there is no source "%s" to rebuild alone (reindex the whole index).',
                    $definition->name,
                    $options->source,
                    implode(', ', array_map(static fn(IndexSource $s): string => $s->name, $definition->sources)),
                ));
            }
            // one source is rebuilt in place: its rows are removed when the source no longer returns them, the other rows are left alone
            $definition = $definition->withOnlySource($options->source);
        }

        return (new Reindexer($this->engine))->run($definition, $options);
    }

    /**
     * Rebuilds only the vocabulary of a fuzzy index ("did you mean": its words and in how many documents
     * each occurs) from the documents already indexed; a full reindex does it after the documents.
     *
     * @return int the number of words
     *
     * @throws InvalidArgument when the engine keeps no vocabulary or the index has no fuzzy field
     */
    public function rebuildVocabulary(string $index): int
    {
        return (new Reindexer($this->engine))->vocabulary($this->registry->get($index));
    }

    /**
     * Search-as-you-type: the search text with the word being typed completed from the index's
     * vocabulary ("wireless hea" -> "wireless headphones", "wireless headset"), the most frequent
     * word first. Only the last word is completed, and only when the text ends with it (not with a
     * space or a symbol). The completions are in the index's normalised form (lowercase, accents
     * folded) and plain text, not HTML: escape them when you render them.
     *
     * The vocabulary is filled by a full reindex (and `fuzzphony:reindex --vocabulary`), so a word that
     * is new since the last rebuild is not suggested yet. A tenant-scoped index, an index without a
     * typo-tolerant field, and an engine without a vocabulary give an empty list.
     *
     * Made as the actor you name, else as the application's current user (anonymous when there is none), the guards run: a viewer who may not see every field of the index gets no
     * completions (the vocabulary holds the words of every field).
     *
     * @param int  $limit  1 to 20
     * @param bool $system as SearchBuilder::asSystem(): no guard runs (a completion lists the words of every field)
     * @param bool $charge a search made as an actor spends a rate-limit token; pass false only when the same request
     *                     already charged a search (the Live Component renders its result and its completions in one request)
     *
     * @return list<string>
     *
     * @throws InvalidArgument for a limit outside 1 to 20
     * @throws AccessDenied    when a guard refuses the actor
     * @throws RateLimited     when the actor searched too often
     */
    public function complete(string $index, string $text, int $limit = 8, ?Actor $actor = null, bool $system = false, bool $charge = true): array
    {
        if ($limit < 1 || $limit > 20) {
            throw new InvalidArgument(sprintf('The suggestion limit must be between 1 and 20, got %d.', $limit));
        }
        $definition = $this->registry->get($index);
        // cut where a search is cut (max_query_length of the index), not at a number of its own
        $text = mb_substr(ltrim($text), 0, $definition->thresholds->limits->maxQueryLength);
        if ($this->gate !== null) {
            if ($system) {
                $this->gate->assertSystemAllowed($definition);
            } else {
                $actor ??= $this->gate->ambientActor();
                if ($actor !== null && !$this->gate->accessCounted($definition, $actor, $charge, $text)->isAll()) {
                    return [];
                }
            }
        }
        if (preg_match('/^(.*?)([\p{L}\p{N}]+)$/su', $text, $m) !== 1) {
            return [];
        }

        return array_map(static fn(string $word): string => $m[1] . $word, $this->engine->suggestWords($definition, $m[2], $limit));
    }

    /**
     * Replaces the synonyms of an index at run time (for example from a database table the application
     * keeps); the next search uses them. No schema change, no reindex. The application loads them
     * itself, and caches them, once per request or process.
     */
    public function useSynonyms(string $index, \Fuzzphony\Core\Definition\Synonyms $synonyms): void
    {
        $this->registry->register($this->registry->get($index)->withSynonyms($synonyms));
    }

    /**
     * The same synonyms with their members stemmed for the index's language (one database round trip),
     * for an application that caches them: stem once when the list changes, keep the result (it
     * serializes) in a cache, and hand it to useSynonyms() on every request. Without it the first search
     * of each request stems every member, which costs about 50 microseconds per entry.
     */
    public function stemSynonyms(string $index, Synonyms $synonyms): Synonyms
    {
        $prepared = $this->engine->stemSynonyms($this->registry->get($index), $synonyms);

        // the prepared object also carries the lookup table, so a cache of it holds everything a search needs
        return $prepared->withTable(SynonymExpander::tableOf($prepared, $prepared->stems));
    }

    /**
     * The reports over the search log, and its purge.
     */
    public function analytics(): SearchAnalytics
    {
        return new SearchAnalytics($this->engine, $this->registry);
    }

    /**
     * The names of the registered indexes.
     *
     * @return list<string>
     */
    public function indexes(): array
    {
        return array_keys($this->registry->all());
    }

    /**
     * The definition of one index, by name or by entity class.
     *
     * @throws UnknownIndex when no such index is registered
     */
    public function definition(string $index): IndexDefinition
    {
        return $this->registry->get($index);
    }

    /** @internal */
    public function registry(): IndexRegistry
    {
        return $this->registry;
    }

    /** @internal */
    public function engine(): Engine
    {
        return $this->engine;
    }
}
