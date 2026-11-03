# Roadmap

What each version shipped and what comes next, up to 1.0. No dates are promised. Back to the [README](../README.md).

Steps are ordered by what other things build on: the breaking changes were gathered in one release (0.10.0, released), so the
additive work after it (0.11.0, next) and the stable 1.0.0 do not have to change the API again.

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
- v0.8 Search features: `Fuzzphony::suggest()` completes the word being typed from the 0.7
  vocabulary (a tenant-scoped index suggests nothing), `facets()` counts the values of filters among the
  matches (disjunctive: a facet does not count its own filter; over the candidates, or exact with
  `exactCounts()`, which also makes the total exact), and `federated()` searches several indexes with one
  merged list (reciprocal rank fusion, per-index weights and configuration). The Live Component offers
  completions and facets, `fuzzphony:search` gets `--facet`, `--exact` and `--suggest`, and the demo's
  search boxes use them. Additive: no breaking changes; `fuzzphony:schema --apply` adds a prefix index to
  each vocabulary. See the [CHANGELOG](../CHANGELOG.md#080---2026-10-10).

- v0.9 Security and analytics: one release in three parts, in order. **Identity:** an `Actor` (a tenant and a user),
  `SearchBuilder::asActor()`, the `fuzzphony.actor` configuration (your own resolver, or property paths on the Security user),
  a 403 for a missing tenant, and tenant-scoped indexes through the API Platform filter, the Live Component and
  `fuzzphony:search --tenant --user`. **Search log and analytics:** an opt-in log (`fuzzphony.search_log`) with retention, a
  per-index and per-search opt-out and a query redactor, `Fuzzphony::analytics()`, `fuzzphony:analytics` and
  `fuzzphony:search-log:purge`. **Guards:** a rate limit per tenant or user (`fuzzphony.rate_limit`, Symfony RateLimiter, a 429 with
  `Retry-After`) and index- and field-level authorization (`fuzzphony.authorization`, `granted` on a field): a field a viewer may
  not see is not matched, ranked, highlighted, counted or suggested from. The demo shows the log and the guards on two pages under
  "Under the hood": Analytics and Security. All of it is optional and off by default: nothing configured means 0.8's behaviour.
  See the [CHANGELOG](../CHANGELOG.md#090---2026-10-14), [UPGRADE.md](../UPGRADE.md#from-08-to-09), [Security](security.md) and
  [Search log and analytics](analytics.md).
- v0.9.1: `FederatedSearch::unlogged()`; the wizard's exporters keep `log: false`.
- v0.9.2: tests and documentation only (a mutation-testing round).
- v0.9.3: [screenshots](screenshots.md) of the demo.
- v0.9.4: one Composer package, `fuzzphony/fuzzphony`; dead code removed.
- v0.9.5: the performance round: fewer database round trips per search (a plain search 5 to 1), a faster registry in
  production, the Doctrine ORM wiring fixed in a real Symfony kernel; Symfony 8.0 is no longer supported.
- v0.9.6: who searched what (`recentSearches()`, the client IP in the search log), the "Migrating from `LIKE`" guide and
  three recipes, a link check for the docs, and a reviewed list of [known limitations](limitations.md).
- v0.9.7: a security fix for restricted viewers (a hidden field's length no longer changes a phrase match), typo
  highlights, a query of stop words only finds something, a typo-tolerant search in one transaction.
- v0.9.8: the best match of a very common word is no longer lost above `candidate_limit`, a rate limit by client IP for
  anonymous visitors, swapped-letter typos, and the zero-result rescue.
- v0.9.9, the review-fix round: fast searches for restricted viewers through open-class indexes, a cheap typo-tolerant
  branch (`hedphones` 354 to 51 ms at 200 000 rows), the engine split into services, strict validation of YAML
  definitions, a reworked demo and CI, and documentation for a database that already exists (see
  [Joins, several tables and several indexes](configuration.md#joins-several-tables-and-several-indexes)).

- v0.9.10, demo only (the library does not change): the language switch resets the search, an emptied search box
  removes `q` from the URL, a centred menu, a footer at the bottom of the page with the library version and links to
  the docs and GitHub, a dark mode switch, a "why it matched" line for every hit on the Relevance page, refreshed
  images for the [screenshots](screenshots.md), and this roadmap.
- v0.9.11: the exact-title and prefix bonuses now apply to searches made as you type (`asYouType()`).
- v0.9.12, demo only (the library does not change): Tune starts with a query every setting reacts to, with example chips, and the Config wizard has a loadable sample configuration that Tune can start from.
- v0.9.13, demo only (the library behaviour does not change): the benchmark shows hit counts green or red, Tune's "Start from" row fits one line on phones, and the bundle defaults say why they are what they are.
- v0.10.0, the breaking release (every item is in [UPGRADE.md](../UPGRADE.md#from-0913-to-0100) and the [CHANGELOG](../CHANGELOG.md)):
  - **Security by default.** A search that names no actor is made as the application's current user (anonymous where nobody can be
    resolved), so a forgotten `asActor()` can no longer show a `granted` field; full access is the visible `->asSystem()`
    ([Security](security.md#the-actor)). A `granted` field without the authorization guard is an error, and `restrictedToFields()` can only narrow.
  - **Hits know their index.** `Hit::$kind` and a typed `Hit::$id`; `FederatedHit` is gone, a federated result holds plain `Hit`s with `mergedScore`.
  - **A smaller API.** 77 public types ([list](architecture.md#public-api)); everything else is `@internal`. `Fuzzphony::postgres()`, `wizard()` and
    `resetCaches()` replace the constructors and the optional `Engine` interfaces (`Capabilities` and its siblings are gone).
  - **Names cleaned up.** `suggest()` is `complete()`, `useSuggestion` is `useDidYouMean`, and the eleven flat thresholds are four groups
    (`relevance`, `typo`, `rescue`, `limits`) ([Configuration](configuration.md)).
  - **A sturdier engine.** `fuzzphony:schema --apply` is mandatory (`SchemaNotApplied` instead of slower no-column statements); index objects are
    named `fuzzphony_<index>__<kind>` (no collision with an index called `products_open`); a typo-tolerant search in your transaction uses a
    savepoint; one trigger set and one change log per watched table; a worker splits a batch that fails on its data and keeps the culprits
    in the queue with their error; the wizard's YAML writes lists and multi-line texts exactly.
  - **Additive.** `SearchResult::$correctedTo`, a `rescued` flag in the search log, `Fuzzphony::logSearch()` (record a search answered elsewhere),
    `toArray()` and JSON for results and hits ([Your own frontend](integrations.md#your-own-frontend)), `explainMatches()`
    ([Searching](searching.md#what-matched)), `code: true` for invoice numbers and SKUs, and `source: { query_file: ... }` for the SQL of a source query.
  - The demo follows: its catalogue pages say `->asSystem()`, the Security page compares the calls and the Analytics page marks rescued searches.

## Next

### v0.11.0: several sources, additive

Today one index has one source (a table or one `SELECT`), and `#[Searchable]` is not repeatable. 0.11 lets one
application search several places, with no breaking change:

- Repeatable `#[Searchable]` or definition classes: several indexes for one entity (for example a public one and an
  admin one).
- An index with several sources and a `kind` column, usable as a filter and to load the right entity.
- `watch` derived from joins, and composite keys in `watch`, so less has to be written by hand ([Sync](sync.md)).
- An entity loader for several classes.
- Federated search with normalised scores, so results from different indexes compare fairly
  ([Searching](searching.md)).
- A demo page with several indexes (separate search boxes and a combined one) and an ERP-like demo data set for it: orders,
  customers, products and order lines.

### v1.0.0: stable

A stable API and a backward-compatibility promise. PostgreSQL only: no other engine is planned before 1.0.

- The documented public API, from 0.10.
- A policy for what counts as a breaking change after 1.0, and how deprecations are announced and removed
  ([CONTRIBUTING](../CONTRIBUTING.md#backward-compatibility)).
- The documentation stays on GitHub (rendered Markdown, no site generator): the [index](README.md), the
  "[Migrating from `LIKE`](migrating-from-like.md)" guide and recipes ([admin panel](recipes/admin-panel.md),
  [shop](recipes/shop.md), [multi-tenant SaaS](recipes/multi-tenant-saas.md)), all with tested code, and a link
  check in CI.

## After 1.0

Separate projects, in their own repositories:

- Record linkage (`fuzzphony/record-linkage`): probabilistic matching of people and companies with explainable match
  scores; online lookup, batch and incremental deduplication; locale packs (hu, en, de).
- A Laravel Scout driver over the same PostgreSQL engine.
- An ERP-like demo as the showcase: orders, customers, products and order lines in several indexes, searched together and apart,
  with tenants and field authorization.

Also planned later: a Prometheus and Grafana stack next to
the demo, so the Prometheus collector and the dashboard ([`grafana/`](grafana/)) are shown working end to end (until then
only the metric names are verified against the real exposition output).

## Mutation testing

Infection runs in CI: a full run monthly (and on demand, `workflow_dispatch`), and only the changed lines on
pull requests. The first full run (September 2026) killed 85% of 3,944 mutants; after 0.8.1's 459 new tests the full
run of 5,989 mutants scores 96% covered-code MSI (17 minutes on CI); the score is on the README badge, which the full
run on `main` updates. The mutants that still escape in the files worked on are equivalent (a cast that changes nothing, a
key that is never read, a constant only a real sleep would show). About 2,000 mutants are skipped because their covering
tests are slower than the 30 s `timeout` (the reindex, rebuild and candidate-selection paths); they are not in the score.
The test suite is faster since 0.9.9. A further round (a `minCoveredMsi` gate on the full run) is separate from the
releases above.
