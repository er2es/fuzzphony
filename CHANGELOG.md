# Changelog

## Unreleased

* **Fix**: `TRUNCATE` on a source or watched table left stale documents in the index forever
  (PostgreSQL runs no `DELETE` trigger for it), and not even `fuzzphony:reindex` removed them.
  * `trigger` and `queue` sync now add an `AFTER TRUNCATE … FOR EACH STATEMENT` trigger to every
    watched table, at both trigger levels. Truncating a table-sourced index's own table empties the
    index (and drops its queued ids); truncating any other watched table resyncs every indexed
    document and every document the source now returns (queued in `queue` mode, refreshed inside
    the transaction in `trigger` mode: expensive on a big index, see "Known limitations").
    Truncating a single partition directly still fires nothing.
  * A full `fuzzphony:reindex` (without `--from`) now ends by removing orphaned documents, those
    whose row the source no longer returns, in batches, and reports how many
    (`Engine::pruneOrphans()`; `Reindexer::run()` takes an optional `$onPruned` callback). A run
    resumed with `--from` never prunes.
  * `fuzzphony:doctor` reports a missing `TRUNCATE` trigger, and with `--deep` counts orphaned
    documents (a warning; the fix is `fuzzphony:reindex`).
  * The own-table `TRUNCATE` shortcut only empties the index when the source really is empty
    (`TRUNCATE ONLY` on a table-inheritance parent leaves the children's rows in the source and now
    resyncs), and it skips queue rows a running worker holds instead of waiting for them.
  * Pruning is opt-out: `fuzzphony:reindex --no-prune` and `Fuzzphony::reindex(..., prune: false)`
    (`Reindexer::run()` takes `$prune` too), for sessions that see less than the application (row-level
    security, `search_path`). A full run whose source returns no row at all does not prune unless
    `--prune-empty` / `pruneEmpty: true` is given (`$onPruneSkipped` reports it).
    `Fuzzphony::reindex()` now also accepts `$onPruned`.

  **After upgrading, run `fuzzphony:schema --apply`** (idempotent) so existing indexes get the new
  trigger and sync functions, then a full `fuzzphony:reindex` to clear what earlier `TRUNCATE`s
  left behind. `Engine` gained a method, so a third-party engine must implement it.

* **New**: empty-result relaxation. When a query of two or more words finds nothing, each word is
  checked on its own against the searched set (filters and tenant included, with the same exact or
  typo-tolerant condition the search uses) in one probe statement (plus a stop-word lookup when the
  search made none for its fuzzy branch); the words that match nothing are dropped like stop words,
  the search runs once or twice more (strict, then fuzzy), and `SearchResult::$warnings` says
  `No results for all words; ignored words that match nothing: "aluminum".` (`interpretedAs` shows
  the reduced query). On the demo catalogue `wireless mouse aluminum` (the word is only in
  descriptions) returns the 1 666 wireless mice instead of nothing. Words that all match something
  but never together (`mouse kettle`), single words and queries with hits are never relaxed, nor
  is a query whose reduction would leave an AND / OR group with only negations
  (`zzqq -mouse | wireless yyqq`). When the relaxed search finds nothing too, the original empty
  result stands, without a warning. The warning is **plain text** that quotes the user's words (invisible
  format characters removed, cut at 40 characters, each word once): escape it when you render it as
  HTML. New threshold `relax_when_empty` (default `true`, independent of `fuzzy_mode`);
  `explain()` lists the `relaxation probe` and `relaxed: …` statements and shows the plan of the
  last search statement. The probe reads the text indexes through one materialized CTE per word
  (10-35 ms at 200 000 rows) and changes no session or transaction setting. The similarity
  threshold of the fuzzy statements is put back after each statement as well, so a search inside
  a caller's transaction leaves nothing behind.
* **Fix / behaviour change**: typo-tolerant (fuzzy) matching is now **per word**. It used to
  compare the whole query as one string with all fuzzy fields, so one long common word could
  satisfy it on its own: on the demo catalogue `wireles mice` returned 20 000 products (chairs,
  drills, kettles, …) of which 1 666 were mice. Every word must now match on its own, exactly or
  by trigram similarity, through the query's real AND / OR / NOT structure (`FuzzyQueryCompiler`),
  and `wireles mice` returns exactly the 1 666 wireless mice. Consequences:
  * fuzzy result sets get **narrower**. Recall is unchanged for words that live in a fuzzy field,
    but a typo in a word found only in a non-fuzzy field (a description, a category) can no longer
    be matched approximately. Such a query finding nothing is relaxed instead (see the entry above):
    `wireless mouse aluminum` lists the wireless mice again, now with a warning naming the ignored
    word;
  * negations are honoured at any depth by the fuzzy branch (previously only top-level ones);
  * `r_fuzzy` / `ScoreBreakdown::$fuzzySimilarity` is now per-word (1.0 for an exact word, AND =
    mean, OR = max), so scores of strict matches shift slightly in `fuzzy_mode: always`;
  * `fuzzy_min_length` applies per word (short words must match exactly), and stop words of the
    index language are ignored like in the full-text query, at the cost of one tiny extra query
    before a fuzzy statement runs.

  Measured at 1 000 000 rows: at most 1.3x the old fuzzy statement's time, still using the GIN
  indexes (no sequential scan). `SearchSqlBuilder::ranked()` (`@internal`) changed signature.
  `NodeInspector::topLevelExclusions()`, which no engine uses any more, was removed.
* **Breaking (pre-1.0)**: the minimum Symfony version is now 7.4 (was 7.3). 7.3 is end-of-life and
  every `symfony/yaml` 7.3.x release carries security advisories, so Composer refuses to install
  a 7.3-pinned set at all. CI now tests 7.4 and 8.0.
* **Fix**: `<twig:Fuzzphony:Search />` failed with "There are no registered paths for namespace
  Fuzzphony" in every real installation: `FuzzphonyBundle::getPath()` pointed one directory too
  high because the bundle class sits at the package root. It now returns its own directory.
* **Fix**: `fuzzphony:search`'s `-p`/`--profile` option collided with Symfony framework-bundle's
  own global `--profile` console-run-profiling flag, throwing "An option named 'profile' already
  exists" the moment the command actually ran through a real Symfony console application (a bare
  `CommandTester` against the isolated command never surfaced it). Renamed the long option to
  `--rank-profile`; `-p` is unchanged.
* **Fix (demo / benchmarks)**: `benchmarks/seed.sql` assigned a product's category independently of
  its name, so a "Wireless chair" could sit in "Mice" and category-name queries tied unrelated
  products. The category now follows the product noun. An already-seeded demo database keeps the
  old data until it is re-seeded and reindexed.
* **Tests**: the Symfony bundle and the Doctrine bridge, previously untested, now have coverage:
  bundle DI wiring, the `fuzzphony:*` console commands, `OrmSyncListener`, `EntityLoader`
  (one query, ranking order), `FuzzphonySearchFilter`, the Live Component, and a regression test
  for console option collisions with FrameworkBundle.
* **CI**: `composer validate --strict` gate, a Symfony 7.4 / 8.0 × PHP 8.4 / 8.5 × PostgreSQL
  15–18 matrix, a `--prefer-lowest` job, a demo smoke job, Codecov upload that no longer fails
  fork PRs, and Dependabot for Composer and GitHub Actions.
* **Docs**: README search-controller examples (minimal and tenant-scoped), a table of contents, the
  two-step index build flow, and corrected example code (filter names are snake_case).
* **Demo**: `demo/docker-compose.yml` is now a production-like stack instead of `php -S` on a bind
  mount: nginx + php-fpm (`web`, `php`), a `worker`, a one-shot idempotent `init` (seed only when
  missing, schema, reindex only when empty, doctor) and a tuned PostgreSQL 17 with a named volume.
  The image is multi-stage and immutable (dependencies, compiled AssetMapper assets and the prod
  cache are baked in; non-root, read-only root filesystem, opcache preload, healthchecks, memory
  limits, log rotation). `docker-compose.dev.yml` keeps live editing. Ports, catalogue size and
  secrets are settable (`.env.example`); the database port is published on 127.0.0.1 only. The old
  `demo` volume is not reused: the first start of the new stack seeds again (`down -v` resets).

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
