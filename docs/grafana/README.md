# Grafana dashboard

`fuzzphony-overview.json` is a sample dashboard for the metrics `PrometheusMetricsCollector`
produces (see [the architecture notes](../architecture.md) and the demo's
[Observability page](../../demo/README.md)). Import it in Grafana ("Dashboards" → "New" →
"Import" → upload this file), pointing it at whatever Prometheus job scrapes your application.

`promphp/prometheus_client_php` exposes counters under the exact name they were registered with (no
`_total` suffix) and histograms with the standard `_sum`/`_count`/`_bucket` suffixes; the panel
queries match that. It is a starting point, not a guarantee that every panel matches your own
label set.
