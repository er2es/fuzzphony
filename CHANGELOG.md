# Changelog

## Unreleased

* **Fix**: `fuzzphony:search`'s `-p`/`--profile` option collided with Symfony framework-bundle's
  own global `--profile` console-run-profiling flag, throwing "An option named 'profile' already
  exists" the moment the command actually ran through a real Symfony console application (a bare
  `CommandTester` against the isolated command never surfaced it). Renamed the long option to
  `--rank-profile`; `-p` is unchanged.

## 0.2.0 — 2026-09-24

* **Multi-tenancy**: `IndexDefinition::tenant` / `IndexBuilder::tenant()` / `#[Searchable(tenant:)]`
  / YAML `tenant:` mark a filter as the tenant scope; every search on a tenant-scoped index must
  call `->forTenant()` (`InvalidQuery::missingTenant()`) and every non-scoped index rejects one
  (`InvalidQuery::unexpectedTenant()`) — enforced in the engine, not left to callers to remember.
  Doctor gained a "Tenant scoping" check; exporters round-trip `tenant`.
* **Column-aware trigger filtering**: a watched table's UPDATE only queues a refresh when a
  relevant column actually changed. Automatic for a table-sourced index's own watch
  (`PostgresSchemaGenerator::relevantColumns()`, derived from its fields/filters/boost/recency);
  opt-in for joined watches via `Watch::$columns` / `.watch(..., columns: [...])` / YAML
  `columns:`. Row-level and statement-level triggers both supported — the statement-level
  (default) trigger was restructured into three mutually exclusive `TG_OP` branches so an
  INSERT-only or DELETE-only invocation never references a transition table it doesn't have.
  Doctor gained a "Column-aware filtering" check, including validation that explicit `columns`
  entries actually exist on the watched table.
* **Configuration wizard**: `fuzzphony:wizard` and `DefinitionSuggester` + `PostgresIntrospector`
  suggest a definition from table structure and planner statistics (joins, filters, boost scaled
  from statistics, recency), explain every decision, export YAML / builder / attributes, `--try` it.
* **Statement-level sync triggers** (default) using transition tables: set-based enqueueing for
  bulk writes. `trigger_level: row` keeps the v0.1 behaviour; the doctor detects leftovers.
* **Per-query ranking overrides**: `->ranking(['boost' => 0.1])`, `RankingProfile::with()/toArray()`.
* **Messenger**: `orm_sync.async` dispatches `RefreshDocuments` messages.
* **API Platform** `FuzzphonySearchFilter`, **Live Component** `<twig:Fuzzphony:Search>`.
* **Demo app** (`demo/`): with/without comparison, playground, web wizard, benchmark, doctor.
* **Benchmarks**: cold + warm timings, `--markdown` / `--json`, CI workflow with job summary.
* BC break (pre-1.0): `OrmSyncListener` now takes a `RefreshDispatcher` instead of an `Engine`.

## 0.1.0 — unreleased

* PostgreSQL engine: weighted full-text search, accent folding, stemming, trigram typo tolerance.
* Index definitions via attributes, YAML or a fluent builder, validated with all violations at once.
* Query language: AND/OR/NOT, phrases, prefixes, field scoping, grouping; never throws on user input.
* Ranking profiles (text, fuzzy, exact/prefix bonus, boost, recency) with score breakdowns.
* Thresholds: min score, fuzzy modes, similarity, candidate limit, input limits.
* Sync modes: queue (default), trigger, ORM, manual; watches for joined tables.
* Doctor with fixes; CLI commands for schema, reindex, worker, search and explain.
