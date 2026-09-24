# Changelog

## 0.2.0 — unreleased

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
