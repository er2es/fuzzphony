# Upgrading

Before 1.0, a minor version may contain breaking changes. Each section lists what to change,
and the [CHANGELOG](CHANGELOG.md) has the full list of changes.

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
