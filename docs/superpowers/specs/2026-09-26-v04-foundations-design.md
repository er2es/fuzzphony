# v0.4 Foundations — design

Status: draft for review · 2026-09-26 · Roadmap milestone v0.4 ([docs/roadmap.md](../../roadmap.md#v04-foundations))

## Intent

Later milestones change the sidecar layout (per-field columns in 0.5, a vocabulary table in 0.7), add
events (0.6) and grow the public API. v0.4 sets the ground they build on so none of them has to
refactor it:

1. **One API shape**: every exception is a Fuzzphony exception, no `mixed` withers, reindex takes an
   options object, the public API is explicit (`@internal` everywhere else).
2. **Engine details live in the engine**: Core no longer knows PostgreSQL naming, types or ranking
   literals.
3. **A dedicated schema**: every Fuzzphony database object can live in its own schema.
4. **A versioned sidecar**: the database records which layout and which definition it was built from,
   so the doctor and later upgrades know what changed.
5. **Doctrine Migrations** work with it: the generated migration is safe to commit and Doctrine's own
   schema diff never proposes dropping Fuzzphony's objects.

Success: all five are in, 100% line coverage, no escaped mutants on changed lines, every breaking
change listed in CHANGELOG **Breaking** and explained in UPGRADE.md, the demo runs on the dedicated
schema.

Not in scope: new search features, events, zero-downtime reindex (0.5), moving an existing install
between schemas automatically.

## Rulings

Made in auto mode; each says why and what it costs if wrong.

### R1 Exceptions

- Rule: **everything src/ throws implements `FuzzphonyException`**, except `\LogicException` for
  internal invariants (a bug in Fuzzphony, never the caller's fault). Existing classes keep their SPL
  base classes, so every current `catch (\InvalidArgumentException)` keeps working.
- New classes (both `final`, in `Fuzzphony\Core\Exception`):
  - `InvalidArgument extends \InvalidArgumentException implements FuzzphonyException`: a runtime
    argument a developer passed is wrong (batch size < 1, unknown table for the wizard, calling an
    exporter that `supports()` rejects).
  - `InvalidConfiguration extends \InvalidArgumentException implements FuzzphonyException`: engine or
    bundle configuration is wrong (invalid schema / extension schema name, `orm_sync.async` without
    Messenger).
- Enum parsing: every `Enum::from()` on developer input (IndexBuilder, ArrayDefinitionLoader, Weight)
  becomes `tryFrom()` + `InvalidDefinition` naming the allowed values. A YAML typo no longer surfaces
  as a bare `\ValueError`.
- `DoctrineNamingStrategy` composite identifier → `InvalidDefinition`.
- Every database call of `PostgresEngine`, the inspector, the highlighter and `SchemaPlan::apply()`
  goes through `guard()`, so driver exceptions always arrive as `EngineFailure` (previous exception
  kept).
- `SchemaCommand` directory failure: the command prints the error and returns `Command::FAILURE`
  instead of throwing.
- Cost if wrong: small; exception classes are cheap to add later, and the SPL bases keep BC.

### R2 Withers

- `IndexDefinition::with(mixed ...$changes)` is **removed**. It silently ignored unknown keys and wrong
  types. Replaced by one typed wither per property: `withName`, `withSource`, `withFields`,
  `withFilters`, `withWatches`, `withIdType`, `withSync`, `withText`, `withBoostColumn(?string)`,
  `withRecencyColumn(?string)`, `withProfiles`, `withThresholds`, `withEntityClass(?string)`,
  `withTriggerLevel`, `withTenant(?string)`. Each constructs through the constructor (no
  `get_object_vars` spread, per the multi-tenancy convention). `ArrayDefinitionLoader::override()`
  chains them.
- Withers do not run `DefinitionValidator`; loaders and the builder validate at their boundary as
  today. A wither with a wrong type is now a PHP type error instead of a silent no-op.
- `Thresholds::with(array)`, `RankingProfile::with(array)` and `SearchBuilder::thresholds()/ranking()`
  **stay**: the snake_case keys are the documented override format shared with YAML, the CLI
  (`--threshold k=v`) and the demo, and they already throw `InvalidDefinition` on unknown keys.
- `SearchQuery::copy(mixed ...)` is private; it gets the same typed treatment only if touched anyway.
- Breaking: `IndexDefinition::with()` removed (UPGRADE: the replacement per key).
- Cost if wrong: a later need for a bulk wither is additive.

### R3 Reindex options and result

- New `Fuzzphony\Core\Sync\ReindexOptions` (final readonly): `batchSize = 5_000` (≥ 1, else
  `InvalidArgument`), `resumeAfter = null` (int|string|null), `prune = true`, `pruneEmpty = false`,
  `onBatch = null` (`\Closure(int $processed, int|string $lastId): void`).
- New `Fuzzphony\Core\Sync\ReindexResult` (final readonly): `int $written`, `?int $pruned` (null when
  pruning did not run), `bool $pruneSkippedEmptySource`.
- `Fuzzphony::reindex(string $index, ReindexOptions $options = new ReindexOptions()): ReindexResult`
  and `Reindexer::run(IndexDefinition, ReindexOptions): ReindexResult`. The `onPruned` and
  `onPruneSkipped` callbacks go away: the result carries that information. `ReindexCommand` uses the
  facade instead of building a `Reindexer` with eight positional arguments; `--from` reaches the
  facade through `resumeAfter`.
- `Engine::pruneOrphans(IndexDefinition, int $batchSize)` keeps its signature (engine SPI, not user
  API).
- Breaking: `Fuzzphony::reindex()` parameters and return type; `Reindexer::run()`.
- Cost if wrong: low; options objects grow additively.

### R4 Public API and `@internal`

- The public API is the list in "Public API" below. Every other class, interface and enum in src/ gets
  `@internal` (today 11 of 112 are tagged). PHPStan's `@internal` rule then flags accidental use from
  the demo and tests outside the package namespace where configured.
- `docs/architecture.md` gets a "Public API" section with that list; CONTRIBUTING's BC paragraph links
  to it. From 1.0 the BC promise covers exactly that list.
- `Core\Engine\Analyzer` (no implementation, no user) is **deleted**.
- Public API: `Fuzzphony`; `Attribute\{Searchable, SearchField, SearchFilter}`;
  `Definition\{IndexDefinition, IndexBuilder, FieldDefinition, FilterDefinition, Watch, TextConfig,
  Weight, FilterType, IdType, SyncMode, TriggerLevel}`; `Search\{SearchBuilder, SearchResult, Hit,
  ScoreBreakdown, Explanation}`; `Ranking\{RankingProfile, Thresholds, FuzzyMode}`;
  `Database\{Connection, PdoConnection}`; `Registry\IndexRegistry`; `Sync\{ReindexOptions,
  ReindexResult, RefreshDispatcher, ImmediateRefreshDispatcher}`; `Inspection\{InspectOptions,
  InspectionReport, Check, CheckStatus}`; `Exception\*`; `Engine\Engine` (SPI for custom engines);
  `Wizard\{DefinitionSuggester, SourceIntrospector, Suggestion, Decision}` and
  `Wizard\Export\{YamlExporter, BuilderExporter, AttributeExporter}`; `Engine\Postgres\PostgresEngine`;
  `Bridge\Doctrine\{DbalConnection, EntityLoader, OrmSyncListener}`; `Bundle\FuzzphonyBundle`,
  `Bundle\ApiPlatform\FuzzphonySearchFilter`, `Bundle\Twig\SearchComponent`,
  `Bundle\Messenger\RefreshDocuments`. The implementation plan confirms each name against src/.
- Cost if wrong: tagging too much is fixed by removing a tag (non-breaking); too little would make a
  later change breaking, so the list errs towards internal.

### R5 PostgreSQL out of Core

- New `Fuzzphony\Engine\Postgres\Schema\Names` (internal, final readonly, constructed with the schema
  and the extension schema): every database object name, qualified and quoted: `sidecar($index)`,
  `queue()`, `meta()`, `normFunction()`, `refreshFunction($index)`, `syncFunction($index, $watch)`,
  `textConfig(TextConfig)`, `stopDictionary(TextConfig)`, index and trigger names. It owns the
  63-byte identifier limit (moved from `Core\Support\Identifier::limit`).
- Removed from Core: `IndexDefinition::sidecarTable()`, `TextConfig::configName()`,
  `IdType::sqlType()`, `FilterType::sqlType()` / `compatibleSqlTypes()`,
  `RankingProfile::tsRankWeights()`. Their logic moves to `Names` and a new internal
  `Engine\Postgres\Schema\Types` (SQL types and compatibility) and to `SearchSqlBuilder` (the
  `ts_rank` weights literal).
- Stays in Core, deliberately: the `$fuzzphony$` check in `DefinitionValidator` (a guard on trusted
  input that costs other engines nothing), the 48-character index-name cap (a portable limit that
  keeps every derived name under 63 bytes), docblocks that mention PostgreSQL as today's engine.
- Breaking: the removed Core methods (they were public). UPGRADE names the engine-side replacement,
  mostly "none needed".
- Cost if wrong: medium; these names are used across the engine, so the plan moves them in one task
  with the tests.

### R6 Dedicated schema

- New setting `schema` (default `public`): bundle key `fuzzphony.schema`, `PostgresEngine`
  constructor argument after `extensionSchema`. Validated as a plain identifier
  (`InvalidConfiguration` otherwise).
- In that schema: every sidecar table, the queue table, the new meta table (R7), the norm, refresh and
  sync functions, the text search configurations and stop dictionaries. `fuzzphony:schema --apply`
  runs `CREATE SCHEMA IF NOT EXISTS`. Triggers necessarily stay on the watched tables; they call the
  schema-qualified sync function.
- Every generated and runtime SQL reference is **schema-qualified** through `Names`; nothing relies on
  `search_path` any more (0.3.2 already did this for the extensions). Generated functions also get
  `SET search_path = pg_catalog, pg_temp`, the hardening PostgreSQL recommends for functions, so a
  caller's `search_path` can't redirect an unqualified name inside them.
- The doctor looks objects up by schema (`to_regclass('schema.name')`, `pg_ts_config` joined to its
  namespace). The wizard's introspector hides the Fuzzphony schema and `fuzzphony_*` objects in it.
- Moving an existing install from `public` to another schema is not automatic: the doctor warns when
  the configured schema is empty but Fuzzphony objects exist in `public`, and UPGRADE gives the
  steps (set `schema`, `schema --apply`, full reindex, `schema --drop` on the old schema through a
  one-off `public` config, or drop by hand).
- The default stays `public`, so upgrading without setting it changes no object location.
- The demo switches to `schema: fuzzphony` (its init script, grants and the Languages page's raw SQL
  follow).
- Cost if wrong: high if some reference stays unqualified; the plan adds an integration test that runs
  the whole suite's key flows with the schema **not** on `search_path`.

### R7 Sidecar schema version

- New shared table `<schema>.fuzzphony_meta`: `index_name text primary key`, `layout_version int not
  null`, `definition_hash text not null`, `documents_hash text`, `library_version text not null`,
  `applied_at timestamptz not null`, `reindexed_at timestamptz`. One row per index, plus a row named
  `*` for the shared objects.
- `layout_version`: a constant in the schema generator (starts at 1 = the 0.4 layout). Later
  milestones bump it and add an upgrade step; the generator runs the steps from the stored version up.
  0.4 ships the mechanism with no steps.
- `definition_hash`: sha256 of the definition parts that shape DDL (fields, filters, id type, watches,
  sync mode, trigger level, text config, tenant). `documents_hash`: sha256 of the parts that shape
  document content (source, fields and weights, filters, text config, boost/recency columns), written
  by a completed full reindex.
- `schema --apply` writes the row at the end of the plan (the `--dump-migration` output includes that
  statement). A full reindex writes `documents_hash` and `reindexed_at`.
- Doctor: "Schema version" check: no row → warning "built before 0.4 or never applied, run
  `fuzzphony:schema --apply`"; stored layout older than the library → error with the same fix;
  `definition_hash` differs → error "definition changed since the last apply"; `documents_hash`
  differs or missing → warning "documents were built from another definition, run
  `fuzzphony:reindex <index>`".
- `schema --drop` deletes the index's meta row.
- Cost if wrong: the hash inputs can be refined later; a changed hash only produces a doctor message.

### R8 Doctrine Migrations

- `--dump-migration` keeps writing a full, idempotent `AbstractMigration` (every statement is
  IF NOT EXISTS / CREATE OR REPLACE, so re-running is safe) and now ends with the meta row upsert.
  `down()` stays irreversible.
- Doctrine's schema diff must never see Fuzzphony objects, or `doctrine:migrations:diff` proposes
  dropping the sidecar tables. The bundle prepends a DBAL `schema_filter` excluding them
  (`~^(?!(<schema>\.)?fuzzphony_)~` for `public`; the whole schema otherwise) when DoctrineBundle is
  present, unless the application sets its own filter, in which case the doctor reports it and the
  docs show the regex to merge.
- `doctrine/migrations` goes into `suggest`.
- Cost if wrong: low; the filter is a string the docs can adjust.

## Public API changes summary (CHANGELOG Breaking)

- `IndexDefinition::with()` removed → typed `with*()` methods.
- `Fuzzphony::reindex()` takes `ReindexOptions`, returns `ReindexResult`; `Reindexer::run()` likewise;
  `onPruned` / `onPruneSkipped` replaced by result fields.
- Removed from Core: `IndexDefinition::sidecarTable()`, `TextConfig::configName()`,
  `IdType::sqlType()`, `FilterType::sqlType()`, `FilterType::compatibleSqlTypes()`,
  `RankingProfile::tsRankWeights()`, `Identifier::limit()`, `Core\Engine\Analyzer`.
- Enum-typo and several argument errors now throw Fuzzphony exceptions (`InvalidDefinition`,
  `InvalidArgument`, `InvalidConfiguration`) instead of `\ValueError` / SPL exceptions; the SPL bases
  are kept where they existed.
- About 80 classes become `@internal`.
- After upgrading: `fuzzphony:schema --apply` (creates the meta table and rows, re-creates the
  functions with a fixed `search_path`). No reindex needed.

## Testing

- Unit tests for every new class and wither; every changed exception type asserted by class and
  message.
- Integration: a suite-level test with `schema: fuzzphony_s` and a `search_path` that excludes it,
  running apply, reindex, exact + fuzzy + relaxed search, queue sync, TRUNCATE sync, doctor and drop.
- Meta table: apply writes the row; a changed definition and a stale layout produce the doctor
  messages; reindex writes `documents_hash`.
- Doctrine: the prepended `schema_filter` hides the objects from `SchemaManager::introspectSchema()`
  with the filter applied; the dumped migration contains the meta upsert.
- Coverage stays 100% of src/; the PR's mutation diff job shows no escaped mutants on changed lines
  that point to missing assertions.

## Implementation order (for the plan)

1. Exceptions (R1).
2. Typed withers (R2).
3. ReindexOptions / ReindexResult (R3).
4. `Names` / `Types`, PostgreSQL out of Core (R5).
5. Dedicated schema (R6), built on `Names`.
6. Meta table and doctor check (R7).
7. Doctrine Migrations (R8).
8. `@internal`, Public API docs, Analyzer removal (R4).
9. Demo on `schema: fuzzphony`, CHANGELOG, UPGRADE, docs.
