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
- v0.7 Relevance (current): typo tolerance proportional to the word's length (one typo from 4
  letters, two from 8; `mouse` no longer matches `monitor`; an explicit `fuzzy_similarity` stays
  flat), per-index synonyms (groups and one-way rules, expanded on the query, stemmed by PostgreSQL,
  no reindex), and "did you mean" (`SearchResult::$didYouMean`) from a new vocabulary table that
  every full reindex fills and `fuzzphony:reindex --vocabulary` rebuilds, which 0.8's `suggest()`
  reuses. Breaking: the default typo tolerance (results change; `fuzzy_similarity: 0.3` keeps the old
  behaviour) and `Thresholds::$fuzzySimilarity` is nullable. 0.7.1 adds synonyms from a Solr-format
  file or from the application's own storage (`Fuzzphony::useSynonyms()`) and a synonym editor in the
  demo; 0.7.3 adds `Fuzzphony::stemSynonyms()` so long synonym lists can be cached prepared. See the
  [CHANGELOG](../CHANGELOG.md#070---2026-10-09) and [UPGRADE.md](../UPGRADE.md#from-06-to-07).

## v0.8: Search features

New query-side features. `suggest()` reuses the 0.7 vocabulary table, and federated search comes
last because it needs final ranking to be settled.

### Search-as-you-type

A dedicated, fast `suggest()` API for prefix suggestions, and a debounced dropdown in the Live
Component.

### Facets

Counts per filter value for the current query ("Kitchen (120) · Office (45)"), and an opt-in exact
`total` for queries whose matches exceed `candidate_limit`.

### Federated search

Query several indexes at once, with one merged, cross-index ranking.

## v0.9: Security and analytics

Built on the 0.6 events.

### Search analytics

The most frequent queries and the queries that found nothing, for the people who own the content.

### Security

Audit logging (who searched what, when) and per-tenant / per-user rate limiting. Symfony Security
integration for index- and field-level authorization (for example, keeping a field out of
highlights unless the viewer is authorized). A tenant resolver (for example from the Symfony
Security user) for the API Platform filter, the Live Component and `fuzzphony:search`.

## v1.0: Stable

Enterprise readiness, a stable API and a backward-compatibility promise. PostgreSQL only: no other
engine is planned before 1.0.

### Documentation site

With a "Migrating from `LIKE`" guide and recipes (admin panel, shop, multi-tenant SaaS).

### Mutation testing

Infection runs in CI: a full run monthly, and only the changed lines on pull requests. The first
full run (September 2026) killed 85% of 3,944 mutants; the score is on the README badge. Next:
write tests for the 561 surviving mutants that point to real gaps, reach 90%, then add a
`minMsi` gate so the score can't slip, raised continuously by every pull request after that.

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
