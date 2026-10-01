# Observability

Metrics for search, sync and reindex: query latency, queue depth, error and fallback rates. Back to
the [README](../README.md).

## The three collectors

Every metrics call site (`PostgresEngine`, `Worker`, `RefreshDocumentsHandler`) takes an optional
`Fuzzphony\Core\Observability\MetricsCollector` — `increment()`, `observe()`, `gauge()`, matching
Prometheus's own data model. Nothing is required; pick one:

- `NullMetricsCollector` — the library-level default when a `MetricsCollector` isn't wired at all:
  zero cost, zero behavior.
- `LoggingMetricsCollector` (Core, PSR-3-backed) — one structured `debug`-level log line per call
  (`event`, `by`/`value`, labels). The default for the Symfony bundle when nothing more specific is
  available. `new LoggingMetricsCollector($logger, logQueryText: true)` keeps the search `query`
  text on the logged line (omitted by default — see "Cardinality" below).
- `Fuzzphony\Bundle\Observability\PrometheusMetricsCollector` — translates calls into a
  `Prometheus\CollectorRegistry` (`promphp/prometheus_client_php`, not a required dependency: add
  it yourself with `composer require promphp/prometheus_client_php`).

### Plain PHP

```php
use Fuzzphony\Core\Observability\LoggingMetricsCollector;

$engine = new PostgresEngine($connection, metrics: new LoggingMetricsCollector($logger));
$worker = new Worker($engine, metrics: new LoggingMetricsCollector($logger));
```

### Symfony bundle

The bundle wires a `fuzzphony.metrics` service automatically — `PrometheusMetricsCollector` when
`promphp/prometheus_client_php` is installed and the `apcu` extension is loaded *and* enabled,
`LoggingMetricsCollector` (wired to the app's own `logger`) otherwise. That decision happens when
the service is first built, not when the container is compiled: a CLI worker and php-fpm share one
compiled container but commonly disagree about `apc.enable_cli` (off by default), so baking the
choice in at compile time would let whichever process starts first decide it for both.

`LoggingMetricsCollector`'s lines carry their own Monolog channel, `fuzzphony`, so they can be
routed or excluded without touching the app's default channel:

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        fuzzphony:
            type: stream
            path: '%kernel.logs_dir%/fuzzphony.log'
            level: debug
            channels: ['fuzzphony']
```

Without that handler the lines still go to the default channel at `debug` level — quiet in
production (where the default minimum level is usually `info` or higher), visible with `-vv` in
`bin/console` or a dev-environment log viewer.

Override `fuzzphony.metrics` to supply your own collector, or force one of the two the factory
would otherwise choose between:

```yaml
# config/services.yaml
services:
    fuzzphony.metrics:
        class: Fuzzphony\Bundle\Observability\PrometheusMetricsCollector
        arguments: ['@my_app.prometheus_registry']
```

## Instrumentation points

- `PostgresEngine::guard()` wraps every engine operation (the full zero-downtime rebuild lifecycle,
  queue processing, orphan pruning and more, by its own operation name, e.g.
  `fuzzphony.rebuild.duration_ms`): `fuzzphony.<operation>.duration_ms` (a histogram) always,
  `fuzzphony.<operation>.errors` (a counter) on failure.
- Each search: `fuzzphony.search.took_ms` (a histogram) and `fuzzphony.search.fallback` (a counter,
  incremented when the full-text/fuzzy fallback ran), both labeled `index` (and `query` for
  `LoggingMetricsCollector` when opted in — never for `PrometheusMetricsCollector`, see
  "Cardinality").
- `Worker`: `fuzzphony.queue.depth` (a gauge, once per cycle, reusing `queueSize()`),
  `fuzzphony.queue.processed` (a counter) and `fuzzphony.worker.rebuild_failures` (a counter).
- `RefreshDocumentsHandler` (the ORM-sync Messenger handler): `fuzzphony.messenger.refresh.duration_ms`
  and `fuzzphony.messenger.refresh.errors`.

## Cardinality

A search query's raw text is unbounded cardinality for a Prometheus label — one new time series
per distinct query ever run. `PrometheusMetricsCollector` strips a `query` label before it ever
reaches the registry; `LoggingMetricsCollector`'s `logQueryText` constructor argument (default
`false`) is the only place it can appear, and only in a log line, never a label.

## `/metrics` endpoint

`promphp/prometheus_client_php` doesn't publish an endpoint itself — add a small controller that
renders whatever registry `fuzzphony.metrics` uses:

```php
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\APCng;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MetricsController
{
    #[Route('/metrics')]
    public function __invoke(): Response
    {
        $registry = new CollectorRegistry(new APCng());
        $renderer = new RenderTextFormat();

        return new Response($renderer->render($registry->getMetricFamilySamples()), 200, [
            'Content-Type' => RenderTextFormat::MIME_TYPE,
        ]);
    }
}
```

`APCng` reads whatever the request's own php-fpm worker has in its APCu segment, same as
`fuzzphony.metrics`'s own default — protect the route (it is not sensitive, but it is internal) and
point your Prometheus job's scrape config at it. A sample Grafana dashboard for the resulting
metrics ships in [`docs/grafana/`](grafana/).

## CLI workers and APCu

APCu's cache is private to each process (or, with `mod_php`/FPM, shared only within one worker
pool) — a `fuzzphony:worker` run in its own CLI process does not share counters with php-fpm's
`/metrics` endpoint, and `apc.enable_cli` is commonly off by default, which silently routes the
worker to `LoggingMetricsCollector` instead. If you run `fuzzphony:worker` long-lived and want its
metrics in Prometheus too, either enable `apc.enable_cli` for that process and scrape it on its own
port, or feed its `LoggingMetricsCollector` output to the textfile collector instead (see below).

## `fuzzphony:doctor --format=prometheus`

`bin/console fuzzphony:doctor --format=prometheus` prints `fuzzphony_doctor_check{index=…,
check=…}` (1 = ok, 0 = not ok) and `fuzzphony_queue_depth{index=…}` for queue-mode indexes, instead
of the usual table — see [Console commands](commands.md). A cron or systemd timer can write it to
node_exporter's textfile collector directory:

```
*/5 * * * * bin/console fuzzphony:doctor --format=prometheus --strict > /var/lib/node_exporter/textfile/fuzzphony.prom
```

The exit code still reflects `--strict` as usual; the Prometheus output is written to stdout either
way, so a monitoring system can alert on both the metrics and the command's own exit code.
