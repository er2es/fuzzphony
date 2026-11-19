# Roadmap

What is done and what may come next. No dates are promised. Back to the [README](../README.md). What each release shipped is in the
[CHANGELOG](../CHANGELOG.md); what to change when upgrading is in [UPGRADE.md](../UPGRADE.md).

## Done

- **0.1 to 0.3:** the PostgreSQL engine, attributes / YAML / builder, the query language, ranking profiles, queue / trigger / ORM sync, the doctor, the
  console commands, the wizard, per-word typo tolerance, empty-result relaxation, multi-tenancy.
- **0.4 to 0.6:** one exception hierarchy and a public API, a dedicated schema, Doctrine Migrations integration, zero-downtime reindex
  ([ADR 0008](adr/0008-shadow-rebuild-with-a-change-log.md)), exact field scoping, partition-aware sync, metrics (logging and Prometheus adapters,
  a sample [Grafana dashboard](grafana/)).
- **0.7 to 0.8:** typo tolerance proportional to the word's length, synonyms, "did you mean", `complete()`, facets, federated search.
- **0.9:** identity (`Actor`), the opt-in search log and analytics, the guards (rate limit, index and field authorization), then performance,
  strict definition validation and the docs: the "Migrating from `LIKE`" guide, recipes, [screenshots](screenshots.md), [known limitations](limitations.md).
- **0.10, the breaking release:** security by default (a search without an actor is made as the current user; `->asSystem()` is explicit), `Hit::$kind` and
  typed ids, a smaller public API ([list](architecture.md#public-api)), cleaned-up names, grouped thresholds, a mandatory `schema --apply`.
- **0.11 to 0.12:** several indexes per entity (`#[AsIndexDefinition]`), multi-source indexes, `many: { rank: best }`, `same_row_as`, a smaller sidecar,
  `DefinitionProvider`, tested doc samples, review fixes, typo hits ranked by the likeliest word, a code prefix rescue, a shorter and tidier code base, a `kinds()` filter and a repeatable `#[Searchable]`, a schema apply that is safe under load and in parallel, and no code for installs of earlier releases (0.12.9).
- **1.0:** the stable API and the backward-compatibility promise ([policy](../CONTRIBUTING.md#backward-compatibility)); PostgreSQL only; the documentation stays on GitHub (rendered Markdown): the [index](README.md), the "[Migrating from `LIKE`](migrating-from-like.md)" guide and recipes ([admin panel](recipes/admin-panel.md), [shop](recipes/shop.md), [multi-tenant SaaS](recipes/multi-tenant-saas.md)), all with tested code, and a link check in CI.

## After 1.0

Separate projects, in their own repositories:

- Source `watch` keys that are an expression or several columns, and one composite id per document (today: one plain column, one id column).
- A text search configuration per field (stemming in more than one language in one index).
- A value boundary in a `many` field's vector, so a phrase cannot match across two values (it changes ranking and the index: needs a differential proof; `manyMatch('single')` covers it today).
- Record linkage (`fuzzphony/record-linkage`): probabilistic matching of people and companies with explainable match scores.
- A Laravel Scout driver over the same PostgreSQL engine.
- Hybrid (lexical and semantic) search with pgvector.
- A Prometheus and Grafana stack next to the demo, so the Prometheus collector and the dashboard are shown working end to end.

## Mutation testing

Infection runs in CI: a full run once per release (started on demand), and only the changed lines on pull requests. The full run scores 96 to 97%
covered-code MSI (the number is on the README badge, which the full run on `main` updates). The mutants that still escape in the files worked on are
equivalent (a cast that changes nothing, a key that is never read). About 2,000 mutants are skipped because their covering tests are slower than the
30 s `timeout` (the reindex, rebuild and candidate-selection paths); they are not in the score.
