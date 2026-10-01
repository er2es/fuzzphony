# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/). Before 1.0, minor versions may contain breaking
changes; they are always listed under **Breaking** and explained in [UPGRADE.md](UPGRADE.md).

## [Unreleased]

### Added

- `Fuzzphony\Core\Observability\MetricsCollector`: an optional interface for counters
  (`increment()`), durations (`observe()`) and point-in-time values (`gauge()`).
  `NullMetricsCollector` (the default: zero cost) and `LoggingMetricsCollector` (one structured
  PSR-3 log line per call) ship in Core.
- `PostgresEngine` instruments every operation (`guard()`'s existing try/catch) and each search's
  total latency and fuzzy-fallback rate through an optional `MetricsCollector` (new 4th
  constructor parameter, defaulting to `NullMetricsCollector`).
- `Worker` gauges each index's queue depth once per cycle, counts items processed and counts
  rebuild failures, through the same optional `MetricsCollector` (new 3rd constructor parameter).
- `RefreshDocumentsHandler` (the ORM-sync Messenger handler) observes its own handling duration and
  counts errors, through the same optional `MetricsCollector`.
- `Fuzzphony\Core\Database\TransactionAware`: an optional `Connection` capability
  (`inTransaction(): bool`). When a `Connection` implements it and no outer transaction is open,
  fuzzy searches skip a now-redundant round trip that restored the previous similarity threshold —
  `PostgresEngine`'s own transaction commit already reverts it. `PdoConnection` and `DbalConnection`
  both implement it; a `Connection` that doesn't keeps today's behavior exactly.
- `Fuzzphony\Bundle\Observability\PrometheusMetricsCollector` (internal, bundle service
  `fuzzphony.metrics`): used automatically when `promphp/prometheus_client_php` is installed and
  the `apcu` extension is loaded and enabled; `LoggingMetricsCollector` wired to the app's `logger`
  otherwise. `PostgresEngine`, `Worker` and `RefreshDocumentsHandler` all receive it.

## [0.5.0] - 2026-10-01

**After upgrading, run `fuzzphony:schema --apply`, then one full `fuzzphony:reindex`.** See
[UPGRADE.md](UPGRADE.md#from-04-to-05).

### Breaking

- The sidecar layout is 2. `fuzzphony:schema --apply` upgrades a layout-1 index (a guarded step in
  the index's plan, also in `--dump-migration`) and clears its documents record, so the doctor's
  "Documents" check asks for one full `fuzzphony:reindex`. The documents hash now includes the
  layout.
- Field-scoped queries are exact: `brand:x` searches the `brand` field only, on the full-text and
  on the typo-tolerant side. 0.4 searched every field of the same weight, and any fuzzy field once
  typo tolerance ran, so results of field-scoped queries change (`name:sony` no longer returns
  Sony-brand products). An excluded one (`-brand:x`, also inside a group such as
  `-(brand:x | cable)`) excludes by that field only. A scoped word of a field that is not fuzzy is
  matched exactly only. The index table stores one `tsvector` per field and one normalised text
  per fuzzy field (roughly one more copy of the indexed text).
- `fuzzphony:schema --drop --apply` and `fuzzphony:reindex --prune-empty` now explain what they
  are about to remove and ask for confirmation (default no); a script that ran either
  non-interactively needs the new `--force` option.
- `Engine` has five new methods for the zero-downtime reindex: `beginRebuild(IndexDefinition $index,
  bool $resume = false): bool`, `refreshShadow(IndexDefinition $index, array $ids): int`,
  `finishRebuild(IndexDefinition $index): void`, `abortRebuild(IndexDefinition $index, bool
  $keepShadow = false): void` and `discardLeftoverRebuild(IndexDefinition $index): bool`. Custom
  engines must implement them; an engine that cannot build next to the live index returns `false`
  from `beginRebuild()` (the reindex then runs in place) and from `discardLeftoverRebuild()`, and
  leaves the others empty.
- A full `fuzzphony:reindex` / `Fuzzphony::reindex()` builds the index next to the live one and
  swaps it in (zero-downtime reindex). It needs disk for a second copy of the index while it runs,
  and a role with `CREATE` on Fuzzphony's schema that owns the index table (otherwise it runs in
  place, as before, and the command says so). After a swap `ReindexResult::$pruned` is `null` (the
  orphans went with the old index) and the new `ReindexResult::$swapped` is `true`. A second full
  reindex of the same index while one runs fails with the new `RebuildAlreadyRunning` (an
  `\InvalidArgumentException`). Starting or discarding a rebuild waits at most 3 s for the index
  table's lock, like the swap (long transactions or autovacuum hold it): starting then fails and
  changes nothing, discarding fails and leaves the rebuild behind for the next full run. The run holds a
  session-level advisory lock: behind a transaction-pooling proxy (PgBouncer in transaction mode)
  it can be released on another server connection than the one that took it, which PostgreSQL only
  warns about, and the lock stays held there (later reindexes fail with "already running" and
  `fuzzphony:schema --apply` refuses) until the pooler closes that connection. Reindex with
  `--in-place` there, or over a session connection.
- `fuzzphony:schema --apply` and `--drop --apply` fail while a full rebuild of an index in their
  plan runs, a `fuzzphony:reindex` or the worker's rebuild of a job a `TRUNCATE` queued: run them
  again when it has finished (a deploy pipeline that applies the schema should retry). A
  `--dump-migration` migration is not transactional, so it only refuses while a rebuild is running
  at its guard statement.
- Index names must not contain `__` (two underscores): it is reserved for the objects of an
  index's rebuild (`fuzzphony_<index>__next`, `fuzzphony_<index>__changes`,
  `fuzzphony_refresh_<index>__next`), which an index named `products__next` would share with
  `products`. Such a definition now fails validation (`InvalidDefinition`). Rename the index
  before upgrading: drop the old one with 0.4 (`fuzzphony:schema --drop --apply`), then apply and
  reindex the new name.
- A failed `fuzzphony:reindex` prints the error and the command to resume with (`--from`, plus
  `--in-place` / `--no-prune` when the run wrote in place), and exits with code 1 (instead of an
  uncaught exception); it stops at the first index that fails. A full `--in-place` run discards a
  rebuild a failed run left behind.
- `Engine` has two new methods for the rebuild job a `TRUNCATE` queues:
  `rebuildRequested(IndexDefinition $index): bool` (the worker asks whether a job is queued) and
  `recordRebuildFailure(IndexDefinition $index, string $message): void` (the worker records that
  the rebuild it ran failed, for the doctor). Custom engines must implement them; an engine whose
  queue has no such job returns `false` from the first and leaves the second empty, and its worker
  only drains the queue. The PostgreSQL engine completes the job when a full run succeeds: in the
  swap, or in `pruneOrphans()` at the end of a full in-place run.
- In `queue` mode a `TRUNCATE` that needs a full resync queues one job, the queue row
  `(index_name, '*')`, instead of every document id, and the worker runs it as a full rebuild.
  Code that reads `fuzzphony_queue` directly must skip that row, and a string document id `*` is
  reserved (changes to such a document queue a full rebuild). The job stays queued until a full
  run that started after it succeeds (the worker's, or any full `fuzzphony:reindex`, `--in-place`
  included); a run that fails or is killed keeps it, and a `TRUNCATE` during a run keeps a newer
  one. The `TRUNCATE` trigger now updates that row (`ON CONFLICT … DO UPDATE`), so a role that
  truncates watched tables needs `UPDATE` on `fuzzphony_queue` as well as `INSERT`. The worker role
  builds next to the live index when it may (see the reindex entry above), else in place.
- A full rebuild that fails never stops `fuzzphony:worker`: it writes the error to stderr, keeps
  the job, syncs the index's queued ids and the other indexes as usual, and tries the job again
  after a back-off (1 minute, doubling, at most 1 hour). `fuzzphony:worker --once` then exits with
  code 1. The shared meta table has three new columns (`rebuild_failed_at`, `rebuild_failures`,
  `rebuild_error`, added by `fuzzphony:schema --apply`) and the doctor's "Sync queue" check warns
  "a full rebuild keeps failing" until a full run succeeds.

### Added

- Partition-aware sync: `fuzzphony:schema --apply` puts the `TRUNCATE` trigger on every partition
  of a partitioned watched table, at every level, so truncating one partition is followed. The
  doctor lists partitions without it (one attached after the last apply) or with it disabled, and
  warns at `trigger_level: statement`, where writes that target a partition directly are not
  synced. A detached partition keeps its trigger: the doctor warns, and `fuzzphony:schema --apply`
  and `--drop --apply` remove it. `ATTACH PARTITION` / `DETACH PARTITION` fire no triggers: run
  `fuzzphony:reindex` afterwards.
- The layout step runner: `fuzzphony:schema --apply` upgrades an index built with an older sidecar
  layout.
- Doctor: a "Shared objects" check of the shared objects' version row (`*` in `fuzzphony_meta`): a
  warning when it is missing, an error when its layout is older or newer than the library's, or
  when the schema or extension schema changed since the last apply.
- `fuzzphony:schema --drop --apply` and `fuzzphony:reindex --prune-empty` ask for confirmation
  before running (`--force` skips it, and is required in non-interactive runs).
- The integration tests (`PostgresTestCase::dsn()`) and `benchmarks/run.php` refuse to run against
  a database whose name doesn't contain "test" (integration tests) or "bench"/"test" (benchmark),
  case-insensitive, so `FUZZPHONY_TEST_DSN` / `FUZZPHONY_BENCH_DSN` can no longer be pointed at a
  real database by mistake; see [CONTRIBUTING.md](CONTRIBUTING.md).
- `ReindexOptions::$inPlace` and `fuzzphony:reindex --in-place` write the live index directly (the
  0.4 behaviour: no second copy on disk).
- Doctor: a "Rebuild" check reports a full reindex that did not finish (with how to resume it, or
  what is left over when it cannot be resumed), and one that is running.

## [0.4.0] - 2026-09-27

**After upgrading, run `fuzzphony:schema --apply`, then one full `fuzzphony:reindex`**, and grant the new
`fuzzphony_meta` table to the roles that run the doctor (`SELECT`) and the reindex (`SELECT`,
`UPDATE`). See [UPGRADE.md](UPGRADE.md#from-03-to-04).

### Breaking

- Every exception Fuzzphony throws implements `FuzzphonyException`, except `\LogicException` for
  internal invariants. New `InvalidArgument` (a wrong runtime argument: batch size below 1, an
  unknown table in the wizard, `AttributeExporter::export()` on a definition it cannot express, a
  non-finite number) and `InvalidConfiguration` (an invalid extension schema, `orm_sync.async`
  without Messenger, no index configured); both extend `\InvalidArgumentException`, so existing
  `catch (\InvalidArgumentException)` blocks still work. An enum typo in the builder or YAML
  (`sync: realtime`, `->field('name', 'E')`) is an `InvalidDefinition` naming the allowed values
  instead of a `\ValueError`; a composite Doctrine identifier is an `InvalidDefinition` instead of
  a `\LogicException`; `AttributeExporter::export()` on a joined source throws `InvalidArgument`
  instead of `\LogicException`. Driver errors from `sourceIds()`, `queueSize()`, `explain()`,
  highlighting, the doctor and `SchemaPlan::apply()` arrive as `EngineFailure` (the driver
  exception is its previous exception). `fuzzphony:schema --dump-migration` into a directory that
  cannot be created prints the error and exits 1 instead of throwing.
- `IndexDefinition::with(...)` is removed. It accepted any named argument, ignored unknown keys
  and silently kept the old value on a wrong type. Use the typed withers instead: `withName()`,
  `withSource()`, `withFields()`, `withFilters()`, `withWatches()`, `withIdType()`, `withSync()`,
  `withText()`, `withBoostColumn()`, `withRecencyColumn()`, `withProfiles()`,
  `withThresholds()`, `withEntityClass()`, `withTriggerLevel()`, `withTenant()`. A wrong type is
  now a PHP `TypeError` at the call site.
- `Fuzzphony::reindex(string $index, ReindexOptions $options = new ReindexOptions()): ReindexResult`
  replaces the positional `$batchSize, $onBatch, $onPruned, $prune, $pruneEmpty, $onPruneSkipped`
  and the `int` return value; `Reindexer::run(IndexDefinition, ReindexOptions): ReindexResult`
  likewise. `ReindexResult` has `written`, `pruned` (null when pruning did not run) and
  `pruneSkippedEmptySource`, which replace the `onPruned` / `onPruneSkipped` callbacks. A batch size
  below 1 throws `InvalidArgument` when the options are created.
- PostgreSQL naming and types left Core: `IndexDefinition::sidecarTable()`, `TextConfig::configName()`,
  `IdType::sqlType()`, `FilterType::sqlType()`, `FilterType::compatibleSqlTypes()`,
  `RankingProfile::tsRankWeights()` and `Identifier::limit()` are removed. They now live in the
  engine (`Fuzzphony\Engine\Postgres\Schema\Names` and `Types`, both internal).
- Fuzzphony no longer uses the `search_path` to place or find its objects: they are created in and
  read from one configured schema (`public` unless `schema` is set), and the generated functions are
  re-created with a pinned `search_path`. After upgrading, run `fuzzphony:schema --apply`; an
  install whose objects live outside `public` must set `schema` to that schema. See
  [UPGRADE.md](UPGRADE.md#from-03-to-04), step 5.
- `Engine` has a new method `recordReindex(IndexDefinition $index): void`, called after a full
  reindex. Custom engines must implement it (an empty body is fine).
- The new `fuzzphony_meta` table needs grants: `SELECT` and `UPDATE` for the role that runs
  `fuzzphony:reindex`, `SELECT` for the role that runs `fuzzphony:doctor`. Without `SELECT` the
  doctor's "Schema version" check is a warning with the `GRANT` to run (so `--strict` fails)
  instead of an inspection failure. A schema-wide `GRANT … ON ALL TABLES` must be re-run after the
  first 0.4 `schema --apply`.
- The public API is now explicit ([docs/architecture.md](docs/architecture.md#public-api)): 51
  classes are marked `@internal` (loaders, validators, the query parser and AST, the reindexer and
  worker, the console command classes, the SQL compilers, the schema generator, the doctor, the
  introspector, …) and may change in any release. The unused `Core\Engine\Analyzer` interface is
  removed, and `YamlExporter`'s constructor no longer takes an (internal) `ArrayExporter`.

### Added

- Mutation testing with [Infection](https://infection.github.io) (`composer mutation`): the
  `@default` mutator set against `src/`, running both the unit and integration test suites,
  multi-threaded (each worker gets its own throwaway database). A monthly CI job records the
  baseline MSI; pull requests only mutate their changed lines. No `minMsi` gate yet — see
  [docs/roadmap.md](docs/roadmap.md#mutation-testing). The first full run scored 85% (3,944
  mutants, 17 minutes); the README shows the current score as a badge.
- `PostgresEngine` takes a `schema` argument (default `public`): the schema of every object
  Fuzzphony creates. `fuzzphony:schema --apply` creates it when it is not `public`.
- `fuzzphony.schema` bundle setting (default `public`) for Fuzzphony's own schema; an invalid name
  fails the container build with `InvalidConfiguration`. The doctor looks its objects up in that
  schema and warns when an index is still in `public` from before the setting; the wizard hides
  the dedicated schema.
- `fuzzphony_meta`: `fuzzphony:schema --apply` records per index the sidecar layout version (1),
  a hash of the definition parts that shape the DDL, and the library version; a full reindex
  records a hash of the parts that shape the documents. The doctor's new "Schema version",
  "Definition" and "Documents" checks report a missing record, a layout older or newer than the
  library's, a definition changed since the last apply, and documents built from another
  definition. `--drop` deletes the index's record; `--dump-migration` includes the upsert.
- Doctrine Migrations: with DoctrineBundle, the bundle sets the DBAL `schema_filter` of
  Fuzzphony's connection so `doctrine:migrations:diff` never proposes dropping Fuzzphony's tables.
  An application that sets its own filter keeps it; `fuzzphony:doctor` warns, with the regex to
  merge, only while that filter still lets Fuzzphony's tables through, so a merged filter passes
  `--strict`. With `connection` or `schema` set from a parameter or `%env()%`, the bundle sets no
  filter and the doctor does not check it. `doctrine/migrations` is suggested.

### Changed

- Every generated and runtime statement schema-qualifies Fuzzphony's own objects. The normaliser
  function runs with `search_path = pg_catalog, pg_temp`; the refresh and sync functions keep the
  `search_path` of the session that applied the schema (`SET search_path FROM CURRENT`).
- Roadmap: reordered into milestones 0.4-1.0, so what other features build on ships first (API
  cleanup and the dedicated schema before reindex, events before analytics, the vocabulary table
  before `suggest()`).
- Roadmap: a dedicated schema for Fuzzphony's tables (`schema: fuzzphony`), default `public`.
- Roadmap: record linkage (matching people and companies, with explainable match scores) is
  planned after 1.0.
- Roadmap: the v0.4 Foundations items (API cleanup, dedicated schema, sidecar schema version,
  Doctrine Migrations integration) are listed as done; the sidecar layout's upgrade step runner
  moves to 0.5, with the first layout change.
- README: shortened to what the library does, the demo, install and quickstart; the details moved
  to `docs/`, the install command is `composer require fuzzphony/fuzzphony` (the bundle is not a
  separate package), and new badges show PHPStan, OpenSSF Best Practices, PHP, PostgreSQL and
  Symfony support.
- Demo: the database is PostgreSQL 18.6 (was 17.11). An existing demo needs
  `docker compose down -v` once and seeds again on the next `up --build`.
- Demo: the compare page shows Fuzzphony's results at once and loads the ILIKE column
  separately (`GET /compare/ilike`), so the page feels as fast as Fuzzphony is instead of waiting
  on ILIKE's ~400 ms full scan too.
- Demo: runs on `schema: fuzzphony`; the application role is granted the `fuzzphony` schema's
  tables instead of the `fuzzphony_*` tables in `public`. An existing demo needs
  `docker compose down -v` once.

### Fixed

- An `extension_schema` whose name needs quoting (upper-case letters, e.g. `Ext`) broke the
  normaliser function: its `unaccent` dictionary was looked up as `ext.unaccent`.
- `fuzzphony:doctor`'s Doctrine schema filter check probed only the sync queue table, so an
  application filter that hid it but not `fuzzphony_meta` (or another shared table) passed
  silently; it now probes every shared table name and warns if any of them gets through.

## [0.3.2] - 2026-09-25

No action needed after upgrading: only the generated search statements change.

### Added

- `.bestpractices.json` with the answers for the OpenSSF Best Practices badge.

### Changed

- README: every known limitation now points to its planned fix; the roadmap adds exact field
  scoping, length-aware typo tolerance, an exact `total`, partition-aware sync and a one-job
  resync after `TRUNCATE`.
- CI: every workflow runs with a read-only token and pins its actions to a commit SHA; an OpenSSF
  Scorecard workflow publishes the score shown in the README badge. The demo's base images are
  pinned by digest, and Dependabot keeps them current. The package now contains `.github/`
  (a few KB of workflow files): Scorecard reads the same archive Composer downloads.
- `main` is protected: changes land through pull requests once CI passes.

### Fixed

- PostgreSQL: the trigram operator (`<%`) and `word_similarity()` in the typo-tolerant / relaxed
  search SQL are now schema-qualified with the configured `extension_schema`, like every other
  `pg_trgm` / `unaccent` reference already was. `pg_trgm` and `unaccent` no longer need to be on
  the `search_path`.

## [0.3.1] - 2026-09-25

**After upgrading, run `fuzzphony:schema --apply`, then a full `fuzzphony:reindex`** so accented
stop words are dropped from the index too. See [UPGRADE.md](UPGRADE.md).

### Added

- Demo: a Languages page (`/languages`) that searches a small hand-written catalogue in English,
  German, French, Spanish and Hungarian, one index per language, with one-click examples and the
  lexeme PostgreSQL produced for every query word.
- Demo: the Benchmark and ILIKE vs Fuzzphony pages state why a run takes a while, derived from the
  actual query/run counts, e.g. "Searching 500,000 products (bench_product): 8 queries × (1 cold +
  5 warm runs) × 2 engines = 96 statements."

### Changed

- The test suite covers 100% of the lines in `src/` (539 tests, up from 400 and 91.5%). CI fails
  below 90% line coverage, and Codecov reports the coverage of every pull request's changes.
- Demo: the default catalogue size (`DEMO_ROWS`) is 500 000 products (was 200 000); `shared_buffers`,
  `effective_cache_size`, `maintenance_work_mem` and the `db` container's memory limit were raised to
  match (see `demo/README.md` "Tuning"). Verified at that size in an isolated compose project: cold
  start (empty volume to first response) 54-57 s, `fuzzphony:doctor --deep` reports 500000 of 500000
  documents indexed (100.0%, exact) — see `demo/README.md` "Proven on 500 000 rows".
- README: the demo moved up front; "Why" says who Fuzzphony is for and when it is not the right
  tool; the Messenger section says `symfony/messenger` is optional; the roadmap lists
  search-as-you-type, synonyms, facets, "did you mean", zero-downtime reindex, search analytics
  and a Laravel driver.

### Fixed

- With accent folding (the default), accented stop words were not ignored: `unaccent` ran before
  the stemmer, which then looked up `fur` instead of `für` in its stop-word list. German `für`,
  Hungarian `és`, French `à` (and the like in every language with a stop-word list) were indexed
  and had to match like ordinary words, so `Tasche für Laptop` did not find `Laptop Tasche`, and
  `à` alone matched 13 products of the demo's French catalogue. The configuration
  `fuzzphony_<language>` now drops stop words first, with a new dictionary
  `fuzzphony_<language>_stop` built from the language's own stop-word list; the typo-tolerant
  branch and the empty-result relaxation ignore them too. `fuzzphony:schema --apply` now also
  repairs an existing configuration, and `fuzzphony:doctor` reports one that still keeps accented
  stop words. **Run `fuzzphony:schema --apply`, then a full `fuzzphony:reindex`**, see
  [UPGRADE.md](UPGRADE.md).

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

[Unreleased]: https://github.com/er2es/fuzzphony/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/er2es/fuzzphony/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/er2es/fuzzphony/compare/v0.3.2...v0.4.0
[0.3.2]: https://github.com/er2es/fuzzphony/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/er2es/fuzzphony/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/er2es/fuzzphony/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/er2es/fuzzphony/releases/tag/v0.2.0
