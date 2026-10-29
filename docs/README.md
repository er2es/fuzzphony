# Documentation

This folder is in the Git repository, not in the Composer package (`composer require fuzzphony/fuzzphony` installs just `src/`).

Where to find what. The [README](../README.md) says what Fuzzphony is, how to install it and shows a first search;
the pages below go deeper. Every page links back here or to the README, and a CI check keeps these links working.

If you read in order: the README (install and quick start), then [Index definitions](configuration.md), then
[Searching](searching.md), then whatever your project needs from the rest.

## Getting started

- [README](../README.md#install): requirements, install, the Symfony quick start and the query syntax in brief.
- [Index definitions](configuration.md): how do I describe what to search (attributes, YAML or the builder; joins, several
  tables and several indexes), set up the bundle and build the index?
- [Searching](searching.md): how do I run a search, and what do typo tolerance, synonyms, "did you mean", facets and
  empty-result relaxation do?
- [Migrating from `LIKE`](migrating-from-like.md): I have `WHERE name ILIKE '%mouse%'` today; how do I move that search
  over, and what do I give up?

## Concepts

- [Ranking and thresholds](ranking.md): how is a hit scored, how do I rank one index differently per use case, and what
  decides whether something matches at all?
- [Languages](languages.md): how are stemming, stop words and accents handled, and which languages are covered?
- [Keeping the index in sync](sync.md): how does a change in a table reach the index (sync modes, triggers,
  `TRUNCATE`, reindexing, Messenger)?
- [Multi-tenancy](multi-tenancy.md): how do I make sure a search never returns another tenant's rows?

## Guides and recipes

- [Admin panel](recipes/admin-panel.md): a typo-tolerant search filter on an API Platform collection.
- [Shop](recipes/shop.md): search-as-you-type, facets with counts, synonyms, boosts, and products and categories in one
  list.
- [Multi-tenant SaaS](recipes/multi-tenant-saas.md): tenant isolation, field authorization, a rate limit, and a search
  log with per-customer reports.
- [Integrations](integrations.md): how does Fuzzphony work with Doctrine, API Platform, Symfony UX Live Component and
  Messenger?
- [Screenshots](screenshots.md): what does the demo look like?

## Reference

- [Console commands](commands.md): every `fuzzphony:*` command and its options, the doctor and the configuration wizard.
- [Search log and analytics](analytics.md): how do I log searches, redact them, report the most searched and the
  zero-result texts, and purge old rows?
- [Security](security.md): who may search, how often, and which fields may a viewer search and see?
- [Observability](observability.md): which metrics exist, and how do I wire them up (Prometheus, the bundle, a
  `/metrics` endpoint)?
- [Architecture](architecture.md): how is the code organised into layers, and what is the public API?
- [Known limitations](limitations.md): what does Fuzzphony not do, or do less well than expected, and what should I do
  about it?
- [Tuning `candidate_limit`](tuning-candidate-limit.md): what to set when the indexed texts are long, with numbers.
- [Benchmarks](benchmarks.md): how does it compare with a naive `ILIKE`, and how do I measure it myself?
- [Design decisions (ADRs)](adr/): why is it built this way (sidecar table, queue sync by default, candidate limit,
  shadow rebuild and more)?
- [Grafana dashboard](grafana/README.md): a sample dashboard for the Prometheus metrics.
- [Roadmap](roadmap.md): what did each version ship, and what is planned for 1.0?

## Operations

Running Fuzzphony in production:

- Sync and reindexing: [Keeping the index in sync](sync.md), [Console commands](commands.md).
- Monitoring: [Observability](observability.md) and the [Grafana dashboard](grafana/README.md).
- Access and privacy: [Security](security.md), [the search log](analytics.md) and the
  [security policy](../SECURITY.md) for reporting a vulnerability.
- Capacity: [Benchmarks](benchmarks.md) and [Known limitations](limitations.md).
- Upgrading: [UPGRADE.md](../UPGRADE.md) for what to change between versions and the [CHANGELOG](../CHANGELOG.md) for
  what each release contains.
- Contributing: [CONTRIBUTING.md](../CONTRIBUTING.md).
