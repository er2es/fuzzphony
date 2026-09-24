# Observability — design

Status: approved (design), not yet implemented. Third piece of the v1.0 "enterprise
readiness" roadmap line in `README.md`, after multi-tenancy and column-aware trigger
filtering.

## Problem

Fuzzphony has no way to answer, from the outside, "is search healthy right now?" or "is
the sync queue keeping up?" without manually running `fuzzphony:doctor` or querying
`fuzzphony_queue` by hand. For a v1.0, enterprise-readiness release, three areas need to
become observable:

1. **Sync/queue health** — queue depth, processing throughput, errors.
2. **Search performance** — query latency, fuzzy-fallback rate.
3. **Schema/trigger health** — the existing `fuzzphony:doctor` checks, exportable as a
   periodic snapshot instead of only a human-read CLI table.

## Consumer and mechanism

Primary consumer: an ops/SRE Prometheus + Grafana setup. But Prometheus/Grafana must be
**optional** — most of this value must be available with zero extra infrastructure, via
whatever PSR-3 logger (Monolog in a Symfony app) the application already has. Neither
mechanism is a "degraded fallback" of the other; they're two adapters behind the same
interface, and the logging one is the always-available baseline.

This rules out an in-process-only counter model: PHP is typically stateless (PHP-FPM),
so per-request counters don't persist or aggregate correctly across requests without
external, shared storage (APCu/Redis) or a long-running process. Fuzzphony's `Worker` is
the one already-long-running process (its `run()` loop) — relevant for where a live
gauge naturally gets sampled, though this design does not build an HTTP scrape endpoint
(see Bundle wiring below).

## `MetricsCollector` — the one interface

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

Three shapes: counters (`increment`), durations/histograms (`observe`), point-in-time
values (`gauge`) — this covers Prometheus's model directly and maps trivially onto one
structured log line per call for the logging adapter.

## Implementations

- **`NullMetricsCollector`** — true no-op (all three methods do nothing). The
  library-level default: a raw-PHP user who wires nothing pays zero cost and gets zero
  behavior change from today.
- **`LoggingMetricsCollector`** (Core, uses `Psr\Log\LoggerInterface`) — the
  always-available, batteries-included default. Each call becomes one structured log
  line, e.g.:

  ```json
  {"event": "fuzzphony.search.took_ms", "value": 12.4, "index": "products"}
  ```

  Constructor: `__construct(LoggerInterface $logger, bool $logQueryText = false)`. When
  `$logQueryText` is `true` (opt-in, default `false`), search-related log lines also
  include the raw query text field. This flag affects **only** this logging adapter —
  raw query text is never sent to a Prometheus label (see below for why).
- **`PrometheusMetricsCollector`** (Bundle, optional — see below) — translates calls
  into `promphp/prometheus_client_php`'s `CollectorRegistry` API (APCu storage, the
  standard approach for PHP-FPM). Ships as an adapter class only; this design does not
  add a bundled `/metrics` HTTP route (see Bundle wiring).

### Why raw query text can never be a Prometheus label

A Prometheus label's cardinality must stay bounded (index name: yes, bounded by the
number of configured indexes; raw search term: no, unbounded, one new time series per
distinct query ever run) — unbounded label cardinality is a well-known way to degrade or
crash a Prometheus instance over time. `logQueryText` therefore only exists on
`LoggingMetricsCollector`; `PrometheusMetricsCollector` never reads or forwards it.

## Instrumentation points

### 1. `PostgresEngine::guard()` — the single chokepoint

`guard()` already wraps every operation (`search`, `refresh`, `queue processing`) in a
try/catch that rethrows via `EngineFailure::wrap()`. One change here covers every
operation:

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

`PostgresEngine` gains a constructor param `MetricsCollector $metrics = new NullMetricsCollector()`.

### 2. Search: fallback rate and total latency

`browse()`'s already-computed `$statements` array and the final `SearchResult` already
carry everything needed — `tookMs` and `usedFuzzy` are already computed, this is just
routing them out:

- `observe('fuzzphony.search.took_ms', $result->tookMs, ['index' => $index->name])`
- `increment('fuzzphony.search.fallback', ['index' => $index->name])` — only when the
  fallback branch (`$statements[1]['label'] === 'fallback: full-text + fuzzy'`) actually
  ran, i.e. exactly the existing `$thresholds->fallbackBelow` condition already computes.

### 3. `Worker` — queue depth and throughput

`Worker` gains an optional `MetricsCollector` constructor param. Inside `runOnce()`'s
existing per-index loop:

- `gauge('fuzzphony.queue.depth', (float) $this->engine->queueSize($index), ['index' => $index->name])`
  sampled once per index per `runOnce()` call (reuses the existing `queueSize()` method
  already used by the doctor's queue check — no new query).
- `increment('fuzzphony.queue.processed', ['index' => $index->name], $processed)` after
  the drain loop.

No change to `Worker::run()`'s existing `$onCycle` callback signature — this is
additive, internal instrumentation, not a public API change.

### 4. Doctor: `--format=prometheus` snapshot

New option on the existing `fuzzphony:doctor` command. Reuses the `InspectionReport`/
`Check` objects the command already computes — no new engine calls. Output is
Prometheus text-exposition format, one line per check plus one queue-depth gauge:

```
fuzzphony_doctor_check{index="products",check="Column-aware filtering"} 1
fuzzphony_doctor_check{index="products",check="Tenant scoping"} 1
fuzzphony_queue_depth{index="products"} 0
```

(`1` = `Ok`, `0` = `Warning`/`Error`/`Skipped` — a single boolean gauge per check, matching
node_exporter's textfile-collector convention.) Intended usage: a cron/systemd-timer
writing this command's stdout to a `.prom` file for node_exporter's textfile collector —
this design does not add a live HTTP endpoint for it (same reasoning as the Prometheus
adapter: bounded scope, most ops setups already have their own textfile-collector
plumbing).

## Bundle wiring

- **Default (no extra config)**: the bundle registers `LoggingMetricsCollector`, wired to
  the application's own `logger` service (Symfony apps always have one) — so every
  Symfony user gets structured observability with zero configuration, matching the
  "enterprise, no Grafana needed" requirement.
- **If `promphp/prometheus_client_php` is installed** (checked via `class_exists`), the
  bundle instead registers `PrometheusMetricsCollector`, backed by an APCu-storage
  `CollectorRegistry`. The bundle does **not** register an HTTP route for it — the
  application wires the adapter's `CollectorRegistry` into whatever `/metrics` endpoint
  it already exposes (most ops setups already have one, or use a metrics bundle that
  provides one). This keeps Fuzzphony's own scope to "translate our events into
  Prometheus's data model," not "run a metrics server."
- `promphp/prometheus_client_php` is added to `composer.json`'s `suggest` (not
  `require`), and to `require-dev` for testing `PrometheusMetricsCollector` itself.
- `psr/log` is added to the root `composer.json`'s `require` — it is an interfaces-only
  package (no implementation shipped), near-ubiquitous across the PHP ecosystem, and
  does not meaningfully compromise the library's current zero-framework-dependency
  core.

## Testing

- Unit: `NullMetricsCollector` (no-op, nothing to assert beyond "doesn't throw");
  `LoggingMetricsCollector` (a spy `LoggerInterface` capturing calls, asserting the
  structured payload shape, and that `logQueryText: false` omits the query field while
  `true` includes it); `PrometheusMetricsCollector` (using promphp's in-memory storage
  adapter in tests, asserting `increment`/`observe`/`gauge` calls translate to the right
  `CollectorRegistry` calls).
- Unit: `PostgresEngine::guard()` — a spy `MetricsCollector` proving `observe(...duration_ms)`
  fires on success and `increment(...errors)` fires on both the `FuzzphonyException`
  passthrough path and the wrapped-`Throwable` path, for at least one representative
  operation (`search` is enough — the code path is identical for all three).
  `Worker::runOnce()` — a spy `MetricsCollector` proving the queue-depth gauge and
  processed counter fire with the right index label and count.
- Integration: the search-fallback counter actually firing only when the real fallback
  branch executes (reuses an existing fallback-triggering fixture query, if the test
  suite already has one — otherwise a minimal repro).
- `fuzzphony:doctor --format=prometheus` — an integration test asserting the output is
  valid Prometheus text-exposition format (line shape, metric names) for a known set of
  checks.

## Non-goals (explicit, to keep this v1.0-scoped)

- No distributed tracing / OpenTelemetry spans.
- No StatsD support.
- No bundled Grafana dashboard JSON (a README addition could follow later; not core
  library scope).
- No bundled `/metrics` HTTP route — the application wires the adapter into its own.
- No alerting rules / SLO definitions — this ships instrumentation, not policy.
- Raw search query text is never sent as a Prometheus label (unbounded cardinality); it
  is only ever an opt-in field on the logging adapter.

## Open questions for the implementation plan

- Exact set of `guard()` operation names to instrument (today: `search`, `refresh`,
  `queue processing` — confirm no other call sites use `guard()` that this design
  missed) — not architecturally significant, a plan-time grep confirms the full list.
- Whether `Reindexer` (bulk reindex, a separate code path from the queue worker) should
  get its own throughput gauge — likely yes (`fuzzphony.reindex.processed`), a small
  addition; left for the implementation plan to size as its own task or fold into the
  Worker task.
