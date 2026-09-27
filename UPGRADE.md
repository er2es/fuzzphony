# Upgrading

Before 1.0, a minor version may contain breaking changes. Each section lists what to change,
and the [CHANGELOG](CHANGELOG.md) has the full list of changes.

## From 0.3 to 0.4

1. **Exceptions.** Catch `Fuzzphony\Core\Exception\FuzzphonyException` to handle everything
   Fuzzphony throws. If you caught `\ValueError` around definition building (a YAML or builder
   enum typo), catch `InvalidDefinition`; if you caught `\LogicException` from
   `AttributeExporter::export()` or `DoctrineNamingStrategy`, catch `InvalidArgument` /
   `InvalidDefinition`; if you caught `\PDOException` or DBAL exceptions around `explain()`,
   highlighting, the doctor or `SchemaPlan::apply()`, catch `EngineFailure` (the driver exception
   is `getPrevious()`). `catch (\InvalidArgumentException)` keeps working.
2. **`IndexDefinition::with()` is gone.** Replace each named argument with its wither, and chain
   them: `$definition->with(sync: SyncMode::Manual, tenant: null)` becomes
   `$definition->withSync(SyncMode::Manual)->withTenant(null)`. The names are the property names:
   `name`, `source`, `fields`, `filters`, `watches`, `idType`, `sync`, `text`, `boostColumn`,
   `recencyColumn`, `profiles`, `thresholds`, `entityClass`, `triggerLevel`, `tenant` →
   `withName()` … `withTenant()`. Withers do not validate; registering the definition does.
3. **Reindexing from PHP.** `reindex()` takes a `ReindexOptions` and returns a `ReindexResult`:

   ```php
   // 0.3
   $written = $fuzzphony->reindex('products', 10_000, $onBatch, onPruned: fn(int $n) => ..., prune: false);
   // 0.4
   $result = $fuzzphony->reindex('products', new ReindexOptions(batchSize: 10_000, onBatch: $onBatch(...), prune: false));
   $written = $result->written;
   $pruned = $result->pruned;                        // was onPruned; null when pruning did not run
   $skipped = $result->pruneSkippedEmptySource;      // was onPruneSkipped
   ```

   `onBatch` must be a `\Closure` (use `$callable(...)` for other callables). The console command
   is unchanged.
4. **Removed Core helpers.** `IndexDefinition::sidecarTable()`, `TextConfig::configName()`,
   `IdType::sqlType()`, `FilterType::sqlType()`, `FilterType::compatibleSqlTypes()`,
   `RankingProfile::tsRankWeights()` and `Identifier::limit()` are gone. Nothing replaces them in
   the public API: the engine derives these names itself. If you queried the sidecar table by
   hand, its name is `fuzzphony_<index>` in Fuzzphony's schema (`public` unless you set one, see
   [Moving to a dedicated schema](#moving-to-a-dedicated-schema)).
5. **Apply the schema once.** `bin/console fuzzphony:schema --apply` re-creates the functions
   with a fixed `search_path` and schema-qualified names. Nothing moves: without a `schema`
   setting everything stays in `public`. Everything is now created in and read from one
   configured schema, so a setup that relied on the `search_path` to place or find Fuzzphony's
   objects (one set per tenant schema, say) no longer works that way. The refresh and sync
   functions resolve your source tables with the `search_path` of the session that applies the
   schema: apply it with the same role and settings as your application. It also creates
   `fuzzphony_meta` and records each index's layout and definition. Until the next full
   `bin/console fuzzphony:reindex`, `fuzzphony:doctor` warns that the documents' definition is
   unknown (check "Documents"); search works without it, but run one reindex before
   `fuzzphony:doctor --strict` in CI. The role that runs `fuzzphony:reindex` needs `SELECT` and
   `UPDATE` on `fuzzphony_meta`; a schema-wide `GRANT … ON ALL TABLES IN SCHEMA` covers only the
   tables that exist, so re-run it after the first 0.4 `schema --apply` (the table is new).
6. **Custom engines** implement `Engine::recordReindex(IndexDefinition $index): void`; an empty
   body is fine if the engine does not track which definition built its documents.
7. **Internal classes.** Only the classes listed under
   [Public API](docs/architecture.md#public-api) are covered by the upgrade notes from now on. If
   you use an `@internal` class directly (`Reindexer`, `Worker`, `ArrayDefinitionLoader`,
   `QueryParser`, `DefinitionValidator`, …), move to the public entry points (`Fuzzphony`,
   `IndexDefinition::builder()`, the bundle's configuration) or open an issue describing the use
   case. PHPStan reports such uses (`@internal` from outside the `Fuzzphony` namespace).

### Moving to a dedicated schema

Optional. Nothing moves by itself. The sync triggers on your tables keep their names in the new
schema, so the new `schema --apply` re-points them to the new functions; a `--drop` with the old
configuration run *afterwards* would remove them again. Two ways:

**Without downtime** (search keeps working from the old tables until the reindex is done):

1. Set `fuzzphony.schema: fuzzphony` (or pass `schema:` to `PostgresEngine`).
2. `bin/console fuzzphony:schema --apply`: creates the schema and every object in it and
   re-points the triggers to the new functions.
3. `bin/console fuzzphony:reindex`: fills the new sidecar tables.
4. Drop the old objects by hand (never with `--drop`, see above):

   ```sql
   DROP TABLE public.fuzzphony_<index>;              -- one per index
   DROP TABLE public.fuzzphony_queue, public.fuzzphony_meta;
   DROP FUNCTION public.fuzzphony_refresh_<index>, public.fuzzphony_sync_<index>__<table>, public.fuzzphony_norm;
   DROP TEXT SEARCH CONFIGURATION public.fuzzphony_<language>;
   DROP TEXT SEARCH DICTIONARY public.fuzzphony_<language>_stop;
   ```

**With a short search outage:**

1. With the old configuration: `bin/console fuzzphony:schema --drop --apply` (removes the
   triggers, functions and sidecar tables; the queue table, `fuzzphony_norm` and the text search
   configurations stay and can be dropped as above).
2. Set the schema, `bin/console fuzzphony:schema --apply`, `bin/console fuzzphony:reindex`.

`fuzzphony:doctor` warns ("Schema") while an old sidecar table is still in `public`.

## From 0.3.1 to 0.3.2

Nothing to do: only the generated search statements change (the trigram operator is now
schema-qualified, so `pg_trgm` no longer has to be on the `search_path`).

## From 0.3.0 to 0.3.1

No code changes. Only indexes with accent folding (`unaccent`, the default) are affected: their
text search configuration kept accented stop words (`für`, `és`, `à`), so the stored documents
contain them.

1. **Apply the schema.** It creates the stop-word dictionary `fuzzphony_<language>_stop` and
   re-maps the existing `fuzzphony_<language>` configuration; queries ignore accented stop words
   from then on.
2. **Reindex every such index.** Documents indexed before still contain the stop words, and
   until they are rebuilt a search can rank them differently.

```bash
bin/console fuzzphony:schema --apply
bin/console fuzzphony:reindex
```

Until you apply the schema, `fuzzphony:doctor` reports that the text search configuration keeps
accented stop words.

## From 0.2 to 0.3

1. **Requirements.** Symfony 7.4 or 8.0 (7.3 is no longer supported), and the `pdo_pgsql` PHP
   extension, which is now a hard requirement of `fuzzphony/fuzzphony`.
2. **Apply the schema, then reindex once.** Sync functions changed and every watched table gets
   a new `TRUNCATE` trigger. Both commands are safe to run on a live index:

   ```bash
   bin/console fuzzphony:schema --apply
   bin/console fuzzphony:reindex        # also removes documents left behind by earlier TRUNCATEs
   ```

   Until you apply the schema, `fuzzphony:doctor` reports the missing `TRUNCATE` trigger.
3. **`fuzzphony:search --profile` is now `--rank-profile`.** The short form `-p` is unchanged.
   Update scripts that use the long option.
4. **A full reindex now prunes orphaned documents** (indexed rows the source no longer returns).
   If the session that runs the reindex sees fewer rows than your application (row-level
   security, a different `search_path`), pass `--no-prune` / `prune: false`, or run it as a role
   that sees everything. A source that returns no row at all is never pruned unless you pass
   `--prune-empty`.
5. **Custom engines** (implementations of `Fuzzphony\Core\Engine\Engine`) must implement
   `pruneOrphans(IndexDefinition $index, int $batchSize = 5_000): int`.
6. **Removed:** `NodeInspector::topLevelExclusions()`. It had no caller in the library; if you
   used it, walk the AST for top-level `Not` nodes yourself.
7. **Behaviour you may notice:**
   - Typo-tolerant results are narrower: every word of the query must now match on its own.
     Tests that pinned the old, wider fuzzy result sets may need new expectations.
   - A multi-word query that finds nothing may now return results for a subset of its words,
     with a warning in `SearchResult::$warnings`. Disable it with the threshold
     `relax_when_empty: false`. The warning quotes the user's words: escape it when rendering HTML.
   - `ScoreBreakdown::$fuzzySimilarity` is computed per word, so absolute scores in
     `fuzzy_mode: always` shift slightly; relative order of strict matches is unaffected.

## From 0.1 to 0.2

- `OrmSyncListener` takes a `RefreshDispatcher` instead of an `Engine`. With the Symfony bundle
  nothing changes; wire `new ImmediateRefreshDispatcher($engine)` yourself otherwise.
