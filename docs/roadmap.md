# Roadmap

What each version shipped and what is planned for 1.0. Back to the [README](../README.md).

Milestones are ordered by what other things build on: a milestone comes before anything that
depends on it, so nothing built early has to be refactored once a later milestone lands.

## Done

- v0.1: PostgreSQL engine, attributes / YAML / builder, query language, ranking profiles,
  thresholds, queue / trigger / ORM sync, doctor, CLI.
- v0.2: configuration wizard (CLI + web), statement-level triggers, per-query ranking overrides,
  Messenger for ORM sync, API Platform filter, Live Component, demo app, benchmark in CI,
  multi-tenancy, column-aware trigger filtering.
- v0.3: per-word typo tolerance, empty-result relaxation, `TRUNCATE` sync and orphan
  pruning, a production-like demo stack.
- v0.4 Foundations: one exception hierarchy, typed withers instead of
  `IndexDefinition::with(...)`, `ReindexOptions` / `ReindexResult`, an explicit public API
  (`@internal` everywhere else) with the PostgreSQL details out of Core; a dedicated schema
  (`schema: fuzzphony`, default `public`); a sidecar schema version (`fuzzphony_meta`, checked by
  the doctor); Doctrine Migrations integration (the bundle's `schema_filter`, the version record in
  `--dump-migration`). See the [CHANGELOG](../CHANGELOG.md#040---2026-09-27) and
  [UPGRADE.md](../UPGRADE.md#from-03-to-04).
- v0.5 Index lifecycle: zero-downtime reindex built in a shadow table and swapped in
  atomically ([ADR 0008](adr/0008-shadow-rebuild-with-a-change-log.md)), one rebuild job for a
  `TRUNCATE` instead of every document id, exact field scoping (per-field text and trigram
  columns), partition-aware sync. The sidecar layout is 2. See the
  [CHANGELOG](../CHANGELOG.md#050---2026-10-01) and [UPGRADE.md](../UPGRADE.md#from-04-to-05).
- v0.6 Events: an optional `MetricsCollector` interface (counters, durations,
  point-in-time values) instruments every engine operation (`PostgresEngine`'s own `guard()`,
  covering search, sync and the full zero-downtime reindex lifecycle in one place), the sync
  worker's queue depth and throughput, and the ORM-sync Messenger handler — with a zero-cost
  default, a structured-logging adapter, and a Prometheus adapter (used automatically once
  `promphp/prometheus_client_php` and `apcu` are both available; a sample Grafana dashboard ships
  in [`docs/grafana/`](grafana/)). An optional, non-breaking `Connection` capability
  (`inTransaction()`) lets a fuzzy statement skip a redundant round trip when no outer transaction
  is open. No breaking changes. See the [CHANGELOG](../CHANGELOG.md#060---2026-10-01).
- v0.7 Relevance: typo tolerance proportional to the word's length (one typo from 4
  letters, two from 8; `mouse` no longer matches `monitor`; an explicit `fuzzy_similarity` stays
  flat), per-index synonyms (groups and one-way rules, expanded on the query, stemmed by PostgreSQL,
  no reindex), and "did you mean" (`SearchResult::$didYouMean`) from a new vocabulary table that
  every full reindex fills and `fuzzphony:reindex --vocabulary` rebuilds, which 0.8's `suggest()`
  reuses. Breaking: the default typo tolerance (results change; `fuzzy_similarity: 0.3` keeps the old
  behaviour) and `Thresholds::$fuzzySimilarity` is nullable. 0.7.1 adds synonyms from a Solr-format
  file or from the application's own storage (`Fuzzphony::useSynonyms()`) and a synonym editor in the
  demo; 0.7.3 adds `Fuzzphony::stemSynonyms()` so long synonym lists can be cached prepared. See the
  [CHANGELOG](../CHANGELOG.md#070---2026-10-09) and [UPGRADE.md](../UPGRADE.md#from-06-to-07).
- v0.8 Search features (current): `Fuzzphony::suggest()` completes the word being typed from the 0.7
  vocabulary (a tenant-scoped index suggests nothing), `facets()` counts the values of filters among the
  matches (disjunctive: a facet does not count its own filter; over the candidates, or exact with
  `exactCounts()`, which also makes the total exact), and `federated()` searches several indexes with one
  merged list (reciprocal rank fusion, per-index weights and configuration). The Live Component offers
  completions and facets, `fuzzphony:search` gets `--facet`, `--exact` and `--suggest`, and the demo's
  search boxes use them. Additive: no breaking changes; `fuzzphony:schema --apply` adds a prefix index to
  each vocabulary. See the [CHANGELOG](../CHANGELOG.md#080---2026-10-10).

## v0.9: Security and analytics

Built on the 0.6 events. One release in three parts, in order: identity, the search log and analytics,
guards. All three parts (identity, the search log and analytics, guards) are done and unreleased; see "Search
analytics" and "Security" below. The demo shows them on two pages under "Under the hood": Analytics and Security.

### Search analytics

The most frequent queries and the queries that found nothing, for the people who own the content.

Done (part 2): an opt-in search log (`fuzzphony.search_log`: `enabled`, `retention_days`, `redact` with the
`email` preset and regular expressions; a per-index `log: false`; `SearchBuilder::unlogged()` and the Live
Component's `log` attribute for as-you-type requests), written in one batch after the response and never
failing a search (`fuzzphony.search_log.failed`), `Fuzzphony::analytics()` (`SearchAnalytics`: top and
zero-result queries, with tenant rules), the commands `fuzzphony:analytics` and `fuzzphony:search-log:purge`,
a doctor check and an Analytics page in the demo. See [Search log and analytics](analytics.md).

### Security

Per-tenant / per-user rate limiting (audit logging is covered by the search log: it records the tenant, the
user and every refusal) and Symfony Security integration for index- and field-level authorization.

Done (part 3, guards): `SearchGuard` and `Access`, `granted` on a field (attribute, builder, YAML),
`restrictedToFields()`, the restricted SQL path (a hidden field is not matched, ranked, highlighted, suggested
or counted), `fuzzphony.rate_limit` (429 with `Retry-After`) and `fuzzphony.authorization` (403), the metrics
`fuzzphony.search.denied` and `fuzzphony.search.rate_limited`, refusals in the search log, and a Security page
in the demo. Row-level authorization, restricted filters and facets, and IP limits are out of scope. See
[Security](security.md).

Done (part 1, identity): the `Actor` (tenant and user), `SearchBuilder::asActor()`, the `fuzzphony.actor`
resolver (a service of yours, or property paths on the Symfony Security user) for the API Platform filter
and the Live Component, `fuzzphony:search --tenant --user`, a 403 for a tenant-scoped index searched
without a tenant, and the counter `fuzzphony.search.denied`. See
[Multi-tenancy](multi-tenancy.md#integrations).

## v1.0: Stable

Enterprise readiness, a stable API and a backward-compatibility promise. PostgreSQL only: no other
engine is planned before 1.0.

### Documentation site

With a "Migrating from `LIKE`" guide and recipes (admin panel, shop, multi-tenant SaaS).

### Mutation testing

Infection runs in CI: a full run monthly (and on demand, `workflow_dispatch`), and only the changed lines on
pull requests. The first full run (September 2026) killed 85% of 3,944 mutants; after 0.8.1's 459 new tests the full
run of 5,989 mutants scores 96% covered-code MSI (17 minutes on CI); the score is on the README badge, which the full
run on `main` updates. The mutants that still escape in the files worked on are equivalent (a cast that changes nothing, a
key that is never read, a constant only a real sleep would show). Next: add a `minCoveredMsi` gate to the full run so the
score can't slip, raised by every pull request after that, and look at the ~1,400 mutants Infection skips because their
covering tests are slower than the 30 s `timeout` (the reindex and rebuild paths): they are not in the score.

### Backward-compatibility promise

A documented policy for what counts as a breaking change after 1.0, and how deprecations are
announced and removed.

## After 1.0

- Laravel integration: a Scout driver over the same PostgreSQL engine.
- Record linkage (`fuzzphony/record-linkage`): probabilistic matching of people and companies
  with explainable match scores; online lookup, batch and incremental deduplication; locale packs
  (hu, en, de).
- Demo: a Prometheus and Grafana stack next to the demo (`apcu`, `promphp/prometheus_client_php`, a
  `/metrics` route, the sample dashboard from `docs/grafana/` preloaded), so the Prometheus collector and
  the dashboard are shown working end to end. Until then only the metric names are verified against the
  real exposition output, not a running Prometheus or Grafana.
