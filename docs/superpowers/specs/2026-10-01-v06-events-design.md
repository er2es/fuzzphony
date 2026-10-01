# v0.6: Events — design

Status: approved (design), not yet implemented. Supersedes
[2026-09-24-observability-design.md](2026-09-24-observability-design.md) (written before the
roadmap was reordered into milestones; its `MetricsCollector` design and reasoning carry over,
updated here against the current code — most of it post-dates v0.5's shadow rebuild and the
destructive-SQL guards). Implements `docs/roadmap.md`'s "v0.6: Events" milestone: Observability and
Transaction-aware connections.

## Problem

1. Fuzzphony has no way to answer, from the outside, "is search healthy right now?", "is the sync
   queue keeping up?" or "did the last reindex succeed?" without running `fuzzphony:doctor` by hand
   or querying `fuzzphony_queue` directly.
2. Every fuzzy search statement pays two extra database round trips (read the previous
   `pg_trgm.word_similarity_threshold` and set the new one in one combined query, then restore the
   previous value afterwards). The restore is only needed when the search runs inside a
   transaction the caller already had open — `PostgresEngine`'s own `transactional()` wrapper
   normally opens and commits its own transaction, and `set_config(..., true)` is transaction-local,
   so that commit already reverts the setting. The restore round trip is wasted in the common case.

## Part 1: Observability

### Consumer and mechanism

Primary consumer: an ops/SRE Prometheus + Grafana setup. But Prometheus/Grafana must be
**optional** — most of this value must be available with zero extra infrastructure, via whatever
PSR-3 logger (Monolog in a Symfony app) the application already has. Neither mechanism is a
"degraded fallback" of the other; they're two adapters behind the same interface, and the logging
one is the always-available baseline.

This rules out an in-process-only counter model: PHP is typically stateless (PHP-FPM), so
per-request counters don't persist or aggregate correctly across requests without external, shared
storage (APCu/Redis) or a long-running process. `Worker::run()`'s loop is the one already
long-running process — relevant for where a live gauge naturally gets sampled, though this design
does not build an HTTP scrape endpoint (see Bundle wiring below).

### `MetricsCollector` — the one interface

New, dependency-free (aside from `psr/log`, added as a `require` — see below), living in
`Fuzzphony\Core\Observability`:

```php
interface MetricsCollector
{
    /** @param array<string, scalar> $labels */
    public function increment(string $event, array $labels = [], int $by = 1): void;

    /** @param array<string, scalar> $labels */
    public function observe(string $event, float $value, array $labels = []): void;

    /** @param array<string, scalar> $labels */
    public function gauge(string $event, float $value, array $labels = []): void;
}
```

Three shapes: counters (`increment`), durations/histograms (`observe`), point-in-time values
(`gauge`) — this covers Prometheus's model directly and maps trivially onto one structured log line
per call for the logging adapter. This is the only new public interface; everything else below is
a consumer or implementation of it.

### Implementations

- **`NullMetricsCollector`** (Core) — true no-op (all three methods do nothing). The library-level
  default: a raw-PHP user who wires nothing pays zero cost and gets zero behavior change from
  today.
- **`LoggingMetricsCollector`** (Core, uses `Psr\Log\LoggerInterface`) — the always-available,
  batteries-included default. Each call becomes one structured log line, e.g.:

  ```json
  {"event": "fuzzphony.search.took_ms", "value": 12.4, "index": "products"}
  ```

  Constructor: `__construct(LoggerInterface $logger, bool $logQueryText = false)`. When
  `$logQueryText` is `true` (opt-in, default `false`), search-related log lines also include the
  raw query text field. This flag affects **only** this logging adapter — raw query text is never
  sent to a Prometheus label (see below for why).
- **`PrometheusMetricsCollector`** (Bundle, optional — see below) — translates calls into
  `promphp/prometheus_client_php`'s `CollectorRegistry` API (APCu storage, the standard approach
  for PHP-FPM). Ships as an adapter class only; this design does not add a bundled `/metrics` HTTP
  route (see Bundle wiring).

#### Why raw query text can never be a Prometheus label

A Prometheus label's cardinality must stay bounded (index name: yes, bounded by the number of
configured indexes; raw search term: no, unbounded, one new time series per distinct query ever
run) — unbounded label cardinality is a well-known way to degrade or crash a Prometheus instance
over time. `logQueryText` therefore only exists on `LoggingMetricsCollector`;
`PrometheusMetricsCollector` never reads or forwards it.

### Instrumentation points

#### 1. `PostgresEngine::guard()` — the single chokepoint

`guard()` (`src/Engine/Postgres/PostgresEngine.php:673`) already wraps every operation in a
try/catch that rethrows via `EngineFailure::wrap()`. Today's operation names, each already a
distinct label: `search`, `explain`, `refresh`, `source ids`, `orphan pruning`, `rebuild` (used by
`beginRebuild`, `refreshShadow`, `finishRebuild`, `abortRebuild`, `discardLeftoverRebuild` alike),
`queue size`, `queue processing`, `rebuild failure record`, `highlighting`. One change here covers
every one of them, including the whole v0.5 zero-downtime reindex lifecycle, for free:

```php
private function guard(string $name, callable $operation, string $hint): mixed
{
    $started = hrtime(true);
    try {
        $result = $operation();
        $this->metrics->observe('fuzzphony.' . $name . '.duration_ms', round((hrtime(true) - $started) / 1e6, 3));

        return $result;
    } catch (FuzzphonyException $e) {
        $this->metrics->increment('fuzzphony.' . $name . '.errors');
        throw $e;
    } catch (\Throwable $e) {
        $this->metrics->increment('fuzzphony.' . $name . '.errors');
        throw EngineFailure::wrap($name, $e, $hint);
    }
}
```

`PostgresEngine` gains a trailing constructor param `MetricsCollector $metrics = new NullMetricsCollector()`
(today: `Connection $connection, string $extensionSchema = 'public', string $schema = 'public'`).
Non-breaking: existing positional or named callers are unaffected.

This resolves the old spec's open question about `Reindexer`: a full reindex (in place or
zero-downtime) only ever calls `Engine` methods that already route through `guard()` under the
`rebuild`/`refresh`/`source ids`/`orphan pruning` labels, so `Reindexer` itself needs no separate
instrumentation — "reindex" observability is already covered transitively. No new task for it.

#### 2. Search: total latency and fallback rate

`PostgresEngine::execute()` (`:327`) already computes everything needed — the final `SearchResult`
already carries `tookMs` and `usedFuzzy`, and `$statements` already carries one entry per
statement actually run, each tagged `['label' => ...]`. Right before `execute()` returns:

- `observe('fuzzphony.search.took_ms', $result->tookMs, ['index' => $index->name])`
- `increment('fuzzphony.search.fallback', ['index' => $index->name])` — only when any entry of
  `$statements` has a label ending in `'fallback: full-text + fuzzy'` (the exact string built at
  `:504`, i.e. the existing `$thresholds->fallbackBelow` branch actually ran, including inside the
  `relaxed: ` empty-result-relaxation path, which reuses the same label prefix mechanism).

This is separate from `guard()`'s per-statement `fuzzphony.search.duration_ms` (one observation per
database round trip; `execute()` can run several for one logical search — browse/full-text,
fallback, relaxation probe, relaxed retry). `fuzzphony.search.took_ms` is the end-to-end latency of
one `Fuzzphony::in(...)->...->get()` call; both are useful and neither replaces the other.

#### 3. `Worker` — queue depth and throughput

`Worker` gains a trailing optional constructor param `?MetricsCollector $metrics = null` (today:
`Engine $engine, ?\Closure $clock = null`), defaulting to `new NullMetricsCollector()` internally.
Inside `runOnce()`'s existing per-index loop (`src/Core/Sync/Worker.php:52`), before the drain
loop:

- `gauge('fuzzphony.queue.depth', (float) $this->engine->queueSize($index), ['index' => $index->name])`
  — reuses the existing `queueSize()` method (already used by the doctor's queue check and already
  routed through `guard('queue size', ...)`, so it gets its own duration/error metrics too) — no
  new query. This is the confirmed, non-breaking answer to "queue lag": a depth (how many items are
  waiting), not a true wait-time-per-item metric, which would need a new `Engine` method. Sized
  this way on purpose (see the Transaction-aware section for why this milestone avoids new
  required `Engine` methods where a cheap, honest proxy exists).
- `increment('fuzzphony.queue.processed', ['index' => $index->name], $processed)` after the drain
  loop, using the loop's own running total.
- The rebuild path (`rebuildIfRequested()`, `:78`) needs no separate instrumentation: it calls
  `$this->reindexer->run(...)`, which — per point 1 — already emits `fuzzphony.rebuild.*` via
  `guard()`. `failed()` (`:95`) additionally increments `fuzzphony.worker.rebuild_failures` with
  the index label, since that path is worker-specific back-off bookkeeping that `guard()` cannot
  see (the engine call that failed already incremented its own `.errors` counter; this one counts
  "the worker gave up on this index this cycle").

No change to `Worker::run()`'s existing `$onCycle` callback signature — this is additive, internal
instrumentation, not a public API change.

#### 4. Messenger: ORM-sync message handling

`RefreshDocumentsHandler` (`src/Bundle/Messenger/RefreshDocumentsHandler.php`) is the handler for
`RefreshDocuments`, dispatched by `MessengerRefreshDispatcher` in ORM sync mode. Rather than
instrument that one handler directly, a small Messenger middleware
`Fuzzphony\Bundle\Messenger\MetricsMiddleware implements MiddlewareInterface` is registered **only
on the bus Fuzzphony's own messages travel** (the bundle already knows which bus that is from
`message_bus` configuration — see `MessengerRefreshDispatcher`'s constructor). It times
`$stack->next()->handle($envelope, $stack)` for any envelope whose message is `RefreshDocuments`
and emits `fuzzphony.messenger.refresh.duration_ms` (observe) and
`fuzzphony.messenger.refresh.errors` (increment, on a caught `\Throwable`, rethrown unchanged) —
the standard Symfony way to add cross-cutting instrumentation to one bus without touching the
handler, and it naturally also covers retries (one metric per handling attempt, matching
Messenger's own retry semantics) without the handler needing to know about `$metrics` at all. This
is the "wired for Symfony Messenger middleware" item from the roadmap; nothing here is specific to
the `queue` sync mode (which never touches Messenger and is fully covered by points 1 and 3).

#### 5. Doctor: `--format=prometheus` snapshot

New option on the existing `fuzzphony:doctor` command (`src/Bundle/Command/DoctorCommand.php`,
alongside the existing `--deep` and `--strict`). Reuses the `InspectionReport`/`Check` objects the
command already computes per index (`Fuzzphony::inspect()` returns one `InspectionReport`, with a
`list<Check>`, each `Check` having a `CheckStatus` of `Ok`/`Warning`/`Error`/`Skipped`) — no new
engine calls. Output is Prometheus text-exposition format, one line per check plus one queue-depth
gauge per index:

```
fuzzphony_doctor_check{index="products",check="Column-aware filtering"} 1
fuzzphony_doctor_check{index="products",check="Tenant scoping"} 1
fuzzphony_queue_depth{index="products"} 0
```

(`1` = `CheckStatus::Ok`, `0` = `Warning`/`Error`/`Skipped` — a single boolean gauge per check,
matching node_exporter's textfile-collector convention; the queue-depth gauge is emitted only for
indexes whose sync mode is `queue`, via `Fuzzphony::engine()`'s existing `queueSize()`, same as
Worker's gauge above.) Intended usage: a cron/systemd-timer writing this command's stdout to a
`.prom` file for node_exporter's textfile collector — this design does not add a live HTTP endpoint
for it (same reasoning as the Prometheus adapter: bounded scope, most ops setups already have their
own textfile-collector plumbing).

### Bundle wiring

- **Default (no extra config)**: the bundle registers `LoggingMetricsCollector`, wired to the
  application's own `logger` service (Symfony apps always have one) — so every Symfony user gets
  structured observability with zero configuration.
- **If `promphp/prometheus_client_php` is installed** (checked via `class_exists`), the bundle
  instead registers `PrometheusMetricsCollector`, backed by an APCu-storage `CollectorRegistry`.
  The bundle does **not** register an HTTP route for it — the application wires the adapter's
  `CollectorRegistry` into whatever `/metrics` endpoint it already exposes. This keeps Fuzzphony's
  own scope to "translate our events into Prometheus's data model," not "run a metrics server."
- `promphp/prometheus_client_php` is added to `composer.json`'s `suggest` (not `require`), and to
  `require-dev` for testing `PrometheusMetricsCollector` itself.
- `psr/log` is added to the root `composer.json`'s `require` — it is an interfaces-only package (no
  implementation shipped), near-ubiquitous across the PHP ecosystem, and does not meaningfully
  compromise the library's current near-zero-dependency core (today: `php`, `ext-mbstring`,
  `ext-pdo`, `ext-pdo_pgsql`).
- `fuzzphony.engine`'s service definition (`src/Bundle/FuzzphonyBundle.php:172`) gains a 4th arg:
  the configured `MetricsCollector` service. `WorkerCommand` gains a constructor param for the same
  service and passes it to `new Worker($engine, metrics: $metrics)`.

## Part 2: Transaction-aware connections

### The round trip

`PostgresEngine::withSimilarityThreshold()` (`:630`) always pays two extra round trips around a
fuzzy statement: one combined query reads the previous `pg_trgm.word_similarity_threshold` and sets
the new one, and a second restores the previous value afterwards. The restore is correctness-
critical only when the statement runs inside a transaction the *caller* already had open before
calling into Fuzzphony — `set_config(name, value, true)` is transaction-local, so when
`Connection::transactional()` opens and commits its own transaction (the common case: no caller
transaction), that commit already reverts the setting and the restore round trip is pure waste.

`PdoConnection::transactional()` (`src/Core/Database/PdoConnection.php`) already special-cases
this: if `$this->pdo->inTransaction()` is true, it reuses the existing transaction instead of
nesting; otherwise it begins and commits its own. That existing check is exactly the signal the
engine needs, but one level up: *before* calling `transactional()`, it needs to know whether doing
so will open a new transaction or join an existing one.

### `TransactionAware` — an optional capability, not a breaking interface change

```php
namespace Fuzzphony\Core\Database;

/** Optional Connection capability: lets the engine skip work that a surrounding transaction's own commit already undoes. */
interface TransactionAware
{
    /** True when a transaction is already open on this connection (the caller's, not one Fuzzphony itself is about to start). */
    public function inTransaction(): bool;
}
```

A new, separate interface rather than a new method on `Connection` — adding a method to
`Connection` would be a breaking change for any custom implementation (as v0.5's `Engine` SPI
additions were, deliberately, under Breaking). `TransactionAware` is checked with `instanceof` at
the one call site that needs it:

- `PostgresEngine::withSimilarityThreshold()` (or its caller) checks
  `$connection instanceof TransactionAware && $connection->inTransaction()` **before** calling
  `$connection->transactional(...)`. When false (no capability, or no open transaction), the
  restore round trip is skipped — `transactional()`'s own commit will revert the transaction-local
  setting. When true, today's full read/set/work/restore sequence runs unchanged, because the
  setting would otherwise leak into the rest of the caller's transaction.
- `PdoConnection` implements `TransactionAware::inTransaction()` as `$this->pdo->inTransaction()`.
- `DbalConnection` implements it as `$this->connection->isTransactionActive()` (DBAL's equivalent).
- A third-party `Connection` implementation that does not implement `TransactionAware` keeps
  today's behavior exactly (both round trips, always) — zero risk, zero required change. This is
  the "optional, non-breaking" capability the roadmap names.

### Scope

This is the only round-trip optimization in this milestone. `PostgresEngine::explain()` (`:107`)
uses the same `withSimilarityThreshold()` helper and benefits automatically. No other statement
pays this cost today.

## Testing

- Unit (`tests/Unit/Core/Observability/`): `NullMetricsCollector` (no-op, nothing to assert beyond
  "doesn't throw"); `LoggingMetricsCollector` (a spy `LoggerInterface` capturing calls, asserting
  the structured payload shape, and that `logQueryText: false` omits the query field while `true`
  includes it). `PrometheusMetricsCollector` (Bundle) goes under `tests/Unit/Bundle/`, using
  promphp's in-memory storage adapter, asserting `increment`/`observe`/`gauge` calls translate to
  the right `CollectorRegistry` calls.
- Unit (`tests/Unit/Postgres/`): `PostgresEngine::guard()` — a spy `MetricsCollector` proving
  `observe(...duration_ms)` fires on success and `increment(...errors)` fires on both the
  `FuzzphonyException` passthrough path and the wrapped-`\Throwable` path, for at least one
  representative operation (`search` is enough — the code path is identical for all labels).
- Unit (`tests/Unit/Core/Sync/`): `Worker::runOnce()` (alongside the existing `WorkerTest.php`) —
  a spy `MetricsCollector` proving the queue-depth gauge and processed counter fire with the right
  index label and count, and `fuzzphony.worker.rebuild_failures` increments on a failed rebuild.
- Integration: the search-fallback counter firing only when the real fallback branch executes
  (reuses an existing fallback-triggering fixture, if the suite already has one); `took_ms`
  observed once per `get()` call regardless of how many internal statements ran.
- Integration: `fuzzphony:doctor --format=prometheus` — output is valid Prometheus text-exposition
  format (line shape, metric names) for a known set of checks.
- Integration (`MetricsMiddleware`): a real Messenger bus with an in-memory transport, asserting
  the duration metric fires once per handled `RefreshDocuments` envelope and the error metric fires
  when the handler throws.
- Integration (`TransactionAware`): a fuzzy search run (a) standalone (no caller transaction — the
  restore round trip is skipped, asserted via a query-count spy or a recording `Connection`) and
  (b) inside a caller-opened `transactional()` block (the restore still runs, and the caller's own
  transaction sees the setting unchanged after the search returns). Also: a `Connection` that does
  not implement `TransactionAware` always takes the pre-existing two-round-trip path.

## Non-goals (explicit, to keep this v0.6-scoped)

- No distributed tracing / OpenTelemetry spans.
- No StatsD support.
- No bundled Grafana dashboard JSON (a docs addition could follow later; not core library scope).
- No bundled `/metrics` HTTP route — the application wires the adapter into its own.
- No alerting rules / SLO definitions — this ships instrumentation, not policy.
- Raw search query text is never sent as a Prometheus label (unbounded cardinality); it is only
  ever an opt-in field on the logging adapter.
- No true time-based "queue lag" metric (time since a row was queued) — `queueSize()`'s depth is
  the chosen, non-breaking proxy; a time-based metric would need a new required `Engine` method and
  is deferred until a concrete need for it shows up.
- No change to the `Connection` interface itself, and no required change for existing custom
  `Connection` or `Engine` implementations — this entire milestone ships with no `### Breaking`
  CHANGELOG entry.

## Breaking changes

None. `psr/log` is a new required dependency (interfaces only), but every other change is an
additive, defaulted constructor parameter or a new, independently-checked optional interface.
