# Roadmap

What each version shipped and what is planned for 1.0. Back to the [README](../README.md).

## Released

- v0.1: PostgreSQL engine, attributes / YAML / builder, query language, ranking profiles,
  thresholds, queue / trigger / ORM sync, doctor, CLI.
- v0.2: configuration wizard (CLI + web), statement-level triggers, per-query ranking overrides,
  Messenger for ORM sync, API Platform filter, Live Component, demo app, benchmark in CI,
  multi-tenancy, column-aware trigger filtering.
- v0.3 (current): per-word typo tolerance, empty-result relaxation, `TRUNCATE` sync and orphan
  pruning, a production-like demo stack.

## v1.0

Enterprise readiness, a stable API and a backward-compatibility promise. PostgreSQL only: no other
engine is planned before 1.0.

### Search-as-you-type

A dedicated, fast `suggest()` API for prefix suggestions, and a debounced dropdown in the Live
Component.

### Synonyms

Synonyms per index (`tv` ↔ `television`, domain abbreviations), expanded on the query side without
dictionary files on the database server.

### Facets

Counts per filter value for the current query ("Kitchen (120) · Office (45)"), and an opt-in exact
`total` for queries whose matches exceed `candidate_limit`.

### Exact field scoping

Per-field text and trigram columns, so `brand:x` searches that field only (not its whole weight
group) and a scoped typo can't match another fuzzy field.

### Length-aware typo tolerance

A stricter similarity for short words and a looser one for long words, so `mouse` stops matching
`monitor` without losing typos in long words.

### Did you mean

A spelling suggestion from the index's own vocabulary when a word matches nothing (`hedphones` →
"headphones?"), next to the existing empty-result relaxation.

### Zero-downtime reindex

Build the new index in a shadow table and swap it in, so a definition change or a full rebuild
never serves partial results. A `TRUNCATE` on a watched table then queues one full-resync job
instead of every document id.

### Partition-aware sync

The `TRUNCATE` trigger on every partition, and the doctor reporting new partitions that miss it.

### Search analytics

The most frequent queries and the queries that found nothing, for the people who own the content.

### Doctrine Migrations integration

Generate a migration class from the schema, next to `--dump-migration`.

### Documentation site

With a "Migrating from `LIKE`" guide and recipes (admin panel, shop, multi-tenant SaaS).

### Observability

Hooks and events for query latency, queue lag and error rate, wired for Symfony Messenger
middleware and any metrics backend.

### Federated search

Query several indexes at once, with one merged, cross-index ranking.

### Security

Audit logging (who searched what, when) and per-tenant / per-user rate limiting. Symfony Security
integration for index- and field-level authorization (for example, keeping a field out of
highlights unless the viewer is authorized). A tenant resolver (for example from the Symfony
Security user) for the API Platform filter, the Live Component and `fuzzphony:search`.

### Transaction-aware connections

A fuzzy statement changes the similarity-threshold setting and restores it afterwards, so a
caller's own transaction is left untouched. That costs one extra database round trip per fuzzy
statement. An optional, non-breaking `Connection` capability (`inTransaction()`) would let the
engine skip that round trip when no outer transaction is open.

### Mutation testing

Set up: Infection runs in CI (a monthly baseline, plus changed-lines-only on pull requests). Next
is raising the Mutation Score Indicator toward a `minMsi` gate and an MSI badge, to show the tests
catch bugs, not just run every line.

## After 1.0

- Laravel integration: a Scout driver over the same PostgreSQL engine.
- Record linkage (`fuzzphony/record-linkage`): probabilistic matching of people and companies
  with explainable match scores; online lookup, batch and incremental deduplication; locale packs
  (hu, en, de).
