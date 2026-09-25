# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/). Before 1.0, minor versions may contain breaking
changes; they are always listed under **Breaking** and explained in [UPGRADE.md](UPGRADE.md).

## [Unreleased]

### Added

- Demo: a Languages page (`/languages`) that searches a small hand-written catalogue in English,
  German, French, Spanish and Hungarian, one index per language, with one-click examples and the
  lexeme PostgreSQL produced for every query word.

## [0.3.0] - 2026-09-25

**After upgrading, run `fuzzphony:schema --apply`** (idempotent) so existing indexes get the new
trigger and sync functions, then a full `fuzzphony:reindex` to clear what earlier `TRUNCATE`s left
behind. See [UPGRADE.md](UPGRADE.md).

### Breaking

- The minimum Symfony version is 7.4 (was 7.3). 7.3 is end-of-life and every `symfony/yaml`
  7.3.x release carries security advisories, so Composer refuses to install a 7.3-pinned set.
- `fuzzphony:search`: the long option `--profile` is now `--rank-profile` (`-p` is unchanged). The
  old name collided with Symfony FrameworkBundle's global `--profile` flag and made the command
  fail with "An option named 'profile' already exists".
- `Engine` gained `pruneOrphans()`; a third-party engine must implement it.
- A full `fuzzphony:reindex` (and `Fuzzphony::reindex()`) now removes orphaned documents at the
  end. Opt out with `--no-prune` / `prune: false`.
- `NodeInspector::topLevelExclusions()` was removed (no engine uses it any more).
  `SearchSqlBuilder::ranked()` (`@internal`) changed signature.
- `Thresholds` rejects `candidate_limit` above 10 000, `max_query_length` above 1 024 and
  `max_terms` above 64, and an unknown `fuzzy_mode` throws `InvalidDefinition` (it used to throw a
  bare `ValueError`).
- A source query or watch SQL that contains `$fuzzphony$` is rejected by the definition validator.
- The `ext-pdo_pgsql` extension is now a hard requirement of `fuzzphony/fuzzphony` (the
  PostgreSQL engine is always part of the package); install it before upgrading.

### Added

- **Empty-result relaxation.** When a query of two or more words finds nothing, each word is
  checked on its own against the searched set (filters and tenant included, with the same exact or
  typo-tolerant condition the search uses) in one probe statement. The words that match nothing
  are dropped and the search runs again; `SearchResult::$warnings` says
  `No results for all words; ignored words that match nothing: "aluminum".` and `interpretedAs`
  shows the reduced query. On the demo catalogue `wireless mouse aluminum` (the word is only in
  descriptions) returns the 1 666 wireless mice instead of nothing.
  - Never relaxed: words that all match something but never together (`mouse kettle`), single
    words, queries with hits, and a reduction that would leave a group of only negations
    (`zzqq -mouse | wireless yyqq`). If the relaxed search finds nothing too, the original empty
    result stands, without a warning.
  - The warning is **plain text** that quotes the user's words (invisible format characters
    removed, cut at 40 characters, each word once): escape it when you render it as HTML.
  - New threshold `relax_when_empty` (default `true`, independent of `fuzzy_mode`). `explain()`
    lists the `relaxation probe` and `relaxed: …` statements and shows the plan of the last search
    statement.
  - The probe reads the text indexes through one materialized CTE per word (10–35 ms at 200 000
    rows) and changes no session or transaction setting.
- **`TRUNCATE` sync.** `trigger` and `queue` sync add an `AFTER TRUNCATE … FOR EACH STATEMENT`
  trigger to every watched table, at both trigger levels. Truncating a table-sourced index's own
  table empties the index when the source really is empty (and drops its queued ids, skipping
  rows a running worker holds); otherwise, and for any other watched table, every indexed document
  and every document the source now returns is resynced (queued in `queue` mode, refreshed inside
  the transaction in `trigger` mode: expensive on a big index, see "Known limitations").
  Truncating a single partition directly still fires nothing.
- **Orphan pruning.** `Engine::pruneOrphans()` removes, in batches, documents whose row the source
  no longer returns. A full reindex calls it and reports the count; a run resumed with `--from`
  never prunes. A full run whose source returns no row at all does not prune unless
  `--prune-empty` / `pruneEmpty: true` is given (`$onPruneSkipped` reports it).
  `Fuzzphony::reindex()` accepts `$onPruned`, `$prune`, `$pruneEmpty` and `$onPruneSkipped`.
- `fuzzphony:doctor` reports a missing `TRUNCATE` trigger and, with `--deep`, counts orphaned
  documents.
- Test coverage for the Symfony bundle and the Doctrine bridge, previously untested: DI wiring,
  the `fuzzphony:*` commands, `OrmSyncListener`, `EntityLoader` (one query, ranking order),
  `FuzzphonySearchFilter`, the Live Component, and console option collisions with FrameworkBundle.
- CI: a `composer validate --strict` gate, a Symfony 7.4 / 8.0 × PHP 8.4 / 8.5 × PostgreSQL 15–18
  matrix, a `--prefer-lowest` job, a demo smoke job, and Dependabot for Composer and GitHub
  Actions.
- `authors`, `homepage` and `support` metadata in `composer.json`; `UPGRADE.md`.

### Changed

- **Typo-tolerant (fuzzy) matching is per word.** It used to compare the whole query as one string
  with all fuzzy fields, so one long common word could satisfy it on its own: on the demo
  catalogue `wireles mice` returned 20 000 products (chairs, drills, kettles, …) of which 1 666
  were mice. Every word must now match on its own, exactly or by trigram similarity, through the
  query's real AND / OR / NOT structure, and `wireles mice` returns exactly the 1 666 wireless mice.
  - Fuzzy result sets get narrower. A typo in a word found only in a non-fuzzy field can no longer
    be matched approximately; such a query finding nothing is relaxed instead (see Added).
  - Negations are honoured at any depth by the fuzzy branch (previously only top-level ones).
  - `ScoreBreakdown::$fuzzySimilarity` is per word (1.0 for an exact word, AND = mean, OR = max),
    so scores of strict matches shift slightly in `fuzzy_mode: always`.
  - `fuzzy_min_length` applies per word, and stop words of the index language are ignored like in
    the full-text query.
  - Measured at 1 000 000 rows: at most 1.3× the old fuzzy statement's time, still on the GIN
    indexes.
- `fuzzphony:doctor` warns about a `candidate_limit` above 5 000 (was 20 000, which is now above
  the cap).
- The similarity threshold a fuzzy statement sets is restored afterwards, so a search inside a
  caller's own transaction leaves no setting behind.
- The dev-only parts of the repository (`demo/`, `benchmarks/`, `docs/`, `tests/`, CI and tool
  configuration) are no longer part of the Composer package.
- Demo: `demo/docker-compose.yml` is a production-like stack (nginx + php-fpm, a worker, a
  one-shot idempotent `init`, a tuned PostgreSQL 17 with a named volume) built from an immutable
  multi-stage image; `docker-compose.dev.yml` keeps live editing. The old demo volume is not
  reused: the first start seeds again.

### Security

- A source query or watch `affectedIds` containing `$fuzzphony$`, the dollar-quote tag of the
  generated functions, could break out of the generated function body; it is now rejected. The
  wizard skips table, column and foreign-key names that are not plain identifiers instead of
  building SQL from them.
- Threshold overrides can no longer lift the cost limits (see Breaking).
- Chained exclusions (`NOT NOT …`, `- - …`) are parsed in a loop instead of recursively; long
  chains are collapsed with the warning `Repeated exclusions ("-" / NOT) were collapsed.`
- Demo: published on 127.0.0.1 only by default, refuses to start exposed with the default secret
  or password, connects as a non-superuser role with a 5 s `statement_timeout`, clamps every
  playground input, makes EXPLAIN ANALYZE and the deep doctor opt-in (`DEMO_ALLOW_ANALYZE`,
  `DEMO_ALLOW_DEEP_DOCTOR`), accepts only listed tables in the web wizard, and escapes the hit
  title when there is no highlight.

### Fixed

- `<twig:Fuzzphony:Search />` failed with "There are no registered paths for namespace Fuzzphony"
  in every real installation: `FuzzphonyBundle::getPath()` pointed one directory too high.
- `TRUNCATE` on a source or watched table left stale documents in the index forever, and not even
  `fuzzphony:reindex` removed them (see Added).
- `TRUNCATE ONLY` on a table-inheritance parent no longer empties the index while the children's
  rows are still in the source.
- Demo: the CSP blocked AssetMapper's importmap entry for `app.js`'s stylesheet import, which
  aborted `app.js` so no JavaScript ran; the demo's `benchmarks/seed.sql` assigned categories
  independently of product names.
- Docs: README examples use snake_case filter names, and the tenant-scoped example uses a
  tenant-scoped index.

## [0.2.0] - 2026-09-24

### Breaking

- `OrmSyncListener` takes a `RefreshDispatcher` instead of an `Engine`.

### Added

- **Multi-tenancy**: `IndexDefinition::tenant` / `IndexBuilder::tenant()` /
  `#[Searchable(tenant:)]` / YAML `tenant:` mark a filter as the tenant scope; every search on a
  tenant-scoped index must call `->forTenant()` (`InvalidQuery::missingTenant()`) and every
  non-scoped index rejects one (`InvalidQuery::unexpectedTenant()`), enforced in the engine.
  Doctor gained a "Tenant scoping" check; exporters round-trip `tenant`.
- **Column-aware trigger filtering**: a watched table's UPDATE only queues a refresh when a
  relevant column changed. Automatic for a table-sourced index's own watch; opt-in for joined
  watches via `.watch(..., columns: [...])` / YAML `columns:`. Doctor gained a "Column-aware
  filtering" check that also validates explicit `columns`.
- **Configuration wizard**: `fuzzphony:wizard` suggests a definition from table structure and
  planner statistics, explains every decision, exports YAML / builder / attributes, `--try` it.
- **Statement-level sync triggers** (default) using transition tables. `trigger_level: row` keeps
  the previous behaviour; the doctor detects leftovers.
- Per-query ranking overrides: `->ranking(['boost' => 0.1])`, `RankingProfile::with()/toArray()`.
- Messenger: `orm_sync.async` dispatches `RefreshDocuments` messages.
- API Platform `FuzzphonySearchFilter`, Live Component `<twig:Fuzzphony:Search>`.
- Demo app (`demo/`): ILIKE comparison, playground, web wizard, benchmark, doctor.
- Benchmarks: cold + warm timings, `--markdown` / `--json`, CI workflow with job summary.

### Initial feature set

0.2.0 is the first tagged release. It also contains the initial feature set that was never tagged
on its own:

- PostgreSQL engine: weighted full-text search, accent folding, stemming, trigram typo tolerance.
- Index definitions via attributes, YAML or a fluent builder, validated with all violations at once.
- Query language: AND/OR/NOT, phrases, prefixes, field scoping, grouping; never throws on user input.
- Ranking profiles (text, fuzzy, exact/prefix bonus, boost, recency) with score breakdowns.
- Thresholds: min score, fuzzy modes, similarity, candidate limit, input limits.
- Sync modes: queue (default), trigger, ORM, manual; watches for joined tables.
- Doctor with fixes; CLI commands for schema, reindex, worker, search and explain.

[Unreleased]: https://github.com/er2es/fuzzphony/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/er2es/fuzzphony/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/er2es/fuzzphony/releases/tag/v0.2.0
