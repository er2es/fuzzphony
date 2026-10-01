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
  route (see Bundle wiring). Prometheus metric names may not contain `.` (must match
  `[a-zA-Z_:][a-zA-Z0-9_:]*`); the adapter maps `$event` to a metric name by replacing every `.`
  with `_` (`fuzzphony.search.took_ms` → `fuzzphony_search_took_ms`), passed as `$name` with
  namespace `''` to `CollectorRegistry::getOrRegisterGauge()` / `getOrRegisterCounter()` /
  `getOrRegisterHistogram()` (`observe()` uses a histogram with promphp's default buckets — this
  design does not expose custom buckets). `$labels`' keys become the metric's label names (fixed
  per call site, e.g. always `['index']` — promphp requires the same label name set on every call
  for one metric, which every call site in this design already satisfies by construction).

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
`RefreshDocuments`, dispatched by `MessengerRefreshDispatcher` in ORM sync mode (today always onto
`messenger.default_bus` — `src/Bundle/FuzzphonyBundle.php:204`, not currently configurable). A
Symfony bus's middleware stack is configured by the *application*, under the bus's own name, in its
own `framework.messenger` config; a bundle cannot safely or portably attach a middleware to a bus it
does not own from inside its own `loadExtension()` — there is no service tag that auto-attaches
middleware to an arbitrary existing bus. Revised from an earlier draft of this design that proposed
exactly that (a standalone `MiddlewareInterface` class the bundle would self-register onto
`messenger.default_bus`): fragile and not what "wired for Symfony Messenger" should mean here.

Instead, `RefreshDocumentsHandler` itself takes the `MetricsCollector` directly (constructor param,
wired by the bundle like every other consumer in this design) and times its own `__invoke()`:

```php
public function __invoke(RefreshDocuments $message): void
{
    $started = hrtime(true);
    try {
        $this->fuzzphony->refresh($message->index, $message->ids);
        $this->metrics->observe('fuzzphony.messenger.refresh.duration_ms', round((hrtime(true) - $started) / 1e6, 3), ['index' => $message->index]);
    } catch (\Throwable $e) {
        $this->metrics->increment('fuzzphony.messenger.refresh.errors', ['index' => $message->index]);
        throw $e;
    }
}
```

This is the handler Fuzzphony fully owns and registers itself (`fuzzphony.messenger.refresh_handler`,
tagged `messenger.message_handler`), so it needs no bus-config cooperation from the application and
works with zero extra config the moment ORM async sync is enabled — a stronger default than an
opt-in middleware the application would have to wire into its own bus by hand. It also still covers
retries (Messenger re-invokes the handler per attempt, so each attempt gets its own observation).
`$this->fuzzphony->refresh(...)` already goes through `guard('refresh', ...)` too, so one failed
message produces both `fuzzphony.refresh.errors` (the underlying engine operation) and
`fuzzphony.messenger.refresh.errors` (this handler gave up on this message) — intentionally two
signals at two levels, same as `Worker`'s `rebuild_failures` counter next to `guard()`'s own
`rebuild.errors`. Nothing here is specific to the `queue` sync mode (which never touches Messenger
and is fully covered by points 1 and 3).

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

#### 6. A Grafana dashboard (added at the maintainer's request)

A static, importable dashboard, `docs/grafana/fuzzphony-overview.json`, built against the metric
names `PrometheusMetricsCollector` produces (see above). Not generated code, not wired into the
bundle — a plain JSON file a Grafana user imports by hand ("Import" → upload JSON), scraping
whatever job name/labels their own Prometheus setup uses. Panels, one per row:

- **Search latency** — a graph of `fuzzphony_search_took_ms` (the histogram's `_sum`/`_count`,
  i.e. average took_ms over time) by `index`.
- **Fuzzy fallback rate** — `rate(fuzzphony_search_fallback_total[5m])` by `index`.
- **Error rate** — `rate(fuzzphony_<op>_errors_total[5m])` summed across every `<op>` label the
  `guard()` instrumentation produces (`search`, `explain`, `refresh`, `source ids`,
  `orphan pruning`, `rebuild`, `queue size`, `queue processing`, `rebuild failure record`,
  `highlighting`), one line per op.
- **Queue depth** — `fuzzphony_queue_depth` by `index` (from the `Worker` gauge and, where the
  worker is not continuously running, `fuzzphony_doctor`'s own `fuzzphony_queue_depth` snapshot —
  same metric name, two sources, intentionally).
- **Queue throughput** — `rate(fuzzphony_queue_processed_total[5m])` by `index`.
- **Worker rebuild failures** — `rate(fuzzphony_worker_rebuild_failures_total[5m])` by `index`.
- **ORM-sync message handling** — `fuzzphony_messenger_refresh_duration_ms` (average) and
  `rate(fuzzphony_messenger_refresh_errors_total[5m])`.
- **Doctor checks** — a table of `fuzzphony_doctor_check` (1 = Ok, 0 = Warning/Error/Skipped) by
  `index`/`check`, for whoever wires the `--format=prometheus` snapshot into node_exporter's
  textfile collector.

`docs/grafana/README.md` (or a section in `docs/sync.md`/a new short doc) explains: it's a sample,
not a guarantee — panel queries assume Prometheus's own `_total`/`_sum`/`_count` suffixing for
counters and histograms (the standard behavior of `promphp/prometheus_client_php`'s exposition
format), and the dashboard has no fixed home in the docs site's navigation beyond a link from the
Observability section. No new PHP code and no new test beyond "the JSON file parses as JSON" (a
one-line unit test, since a hand-edited dashboard JSON can bit-rot into invalid JSON unnoticed).

#### 7. Demo: an Observability page (added at the maintainer's request)

A new demo page, `/observability` (nav label "Observability"), added next to the existing Doctor
page in `demo/templates/base.html.twig`'s `pages` map and `demo/src/Controller/PagesController.php`
(same pattern as `doctor()`). It illustrates the available `MetricsCollector` backends and shows one
real query's own numbers — it does not stand up a live dashboard or new storage, matching "simple"
and reusing only existing public API:

- A short, static explanation of the three backends (`NullMetricsCollector`,
  `LoggingMetricsCollector`, `PrometheusMetricsCollector`) and which one this demo uses by default
  (`LoggingMetricsCollector`, wired to the app's logger with no extra config — see Bundle wiring).
- One live example query, reusing `CompareController::EXAMPLES`'s first entry, run through
  `$fuzzphony->in('catalog')->explain()` (existing public API, no new method) instead of a bare
  `search()`/`get()`, because `Explanation` already exposes exactly what this page needs:
  `$explanation->result->tookMs` (→ shown as `fuzzphony.search.took_ms`) and whether any entry of
  `$explanation->statements` has a label ending in `'fallback: full-text + fuzzy'` (→ shown as
  `fuzzphony.search.fallback`, present or absent).
- The current sync-queue state, reusing `$fuzzphony->inspect('catalog')` (same call the Doctor page
  already makes) and picking out the `Check` named `'Sync queue'` — its existing message already
  reads like `"N item(s) waiting"`, shown as the `fuzzphony.queue.depth` illustration. No new
  `Engine` call.
- A closing note that the full event list (search, sync, reindex, worker, Messenger) streams
  continuously once a `MetricsCollector` is wired; this page shows one query's own numbers as the
  smallest honest illustration, not a live feed.

New template `demo/templates/observability.html.twig` (same structure as `doctor.html.twig`). No
new PHP class in the demo and no new library API — a controller action, a template, a nav entry,
and a `demo/README.md` paragraph (same place the "How the indexes run" table lives).

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
- Unit: `docs/grafana/fuzzphony-overview.json` parses as JSON (`json_decode($contents, flags: JSON_THROW_ON_ERROR)` inside a test, not a runtime check) and every panel's query string contains a metric name this design actually produces.
- Demo: `PublicApiTest::testTheDemoAndTheBenchmarkUseOnlyThePublicApi` already greps the demo's
  `use` statements against the public API list — the new controller action must not introduce an
  `@internal` import. The CI `demo-smoke` job (`.github/workflows/ci.yml`) does not crawl pages over
  HTTP — it runs `php bin/console lint:container`, which already catches a wiring mistake in the
  `fuzzphony.metrics`/`fuzzphony.engine`/`WorkerCommand` service arguments (a missing/misordered
  argument fails container compilation). There is no existing per-page HTTP smoke test to extend for
  `/observability` specifically; this design does not add one.
- Unit (`tests/Unit/Bundle/Messenger/`): `RefreshDocumentsHandler` — a spy `MetricsCollector`
  proving the duration metric fires with the index label on success and the error metric fires
  (and the exception still propagates unchanged) when `Fuzzphony::refresh()` throws.
- Integration (`TransactionAware`): a fuzzy search run (a) standalone (no caller transaction — the
  restore round trip is skipped, asserted via a query-count spy or a recording `Connection`) and
  (b) inside a caller-opened `transactional()` block (the restore still runs, and the caller's own
  transaction sees the setting unchanged after the search returns). Also: a `Connection` that does
  not implement `TransactionAware` always takes the pre-existing two-round-trip path.

## Non-goals (explicit, to keep this v0.6-scoped)

- No distributed tracing / OpenTelemetry spans.
- No StatsD support.
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
