# Grafana dashboard

`fuzzphony-overview.json` is a sample dashboard for the metrics `PrometheusMetricsCollector`
produces (see [the architecture notes](../architecture.md) and the demo's
[Observability page](../../demo/README.md)). Import it in Grafana ("Dashboards" → "New" →
"Import" → upload this file), pointing it at whatever Prometheus job scrapes your application.

It assumes Prometheus's own `_total`/`_sum`/`_count` suffixing for counters and histograms (how
`promphp/prometheus_client_php`'s exposition format already works) — it is a starting point, not a
guarantee that every panel matches your own label set.
