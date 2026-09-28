# v0.5 Index lifecycle — design

Status: draft for review · 2026-09-28 · Roadmap milestone v0.5 ([docs/roadmap.md](../../roadmap.md#v05-index-lifecycle))

## Intent

0.4 gave every index a versioned sidecar in its own schema. v0.5 uses that to make the index's life
safe in production:

1. **Zero-downtime reindex.** A full rebuild never serves partial or mixed results: the new index is
   built next to the live one and swapped in atomically. A `TRUNCATE` on a watched table queues one
   rebuild job instead of every document id.
2. **Exact field scoping.** `brand:x` searches the `brand` field only, on the full-text side and on
   the typo-tolerant side.
3. **Partition-aware sync.** Truncating one partition of a watched partitioned table is followed,
   and the doctor reports partitions that miss the trigger.
4. **The layout step runner.** Field scoping is the first sidecar layout change (layout 1 → 2); the
   runner that upgrades an older layout ships with it. The doctor also checks the shared (`*`)
   version row.

Success: the four items land with 100% line coverage and no escaped mutants on changed lines; the
four matching entries leave docs/limitations.md; every breaking change is in CHANGELOG **Breaking**
and UPGRADE "From 0.4 to 0.5"; the demo shows a field-scoped query and a zero-downtime rebuild.

Not in scope: events/observability (0.6), trigger-mode `TRUNCATE` without an inline resync, moving
data between schemas.

## Rulings

Made in auto mode; each says why and what it costs if wrong.

### R1 Shadow build and swap

- A full reindex (no `resumeAfter`) builds into a **shadow table** `<schema>.fuzzphony_<index>__next`
  with the same layout and indexes as the live sidecar, then swaps it in. Searches read the live
  table the whole time; nothing they see is half-built.
- Writing documents: the generator emits a second refresh function,
  `fuzzphony_refresh_<index>__next(ids)`, identical to the live one except for its target table.
  Two static functions keep today's plan-cached, non-dynamic SQL; no `EXECUTE format()` per batch.
- Changes made while the shadow is being built still reach the live table as today (trigger or
  queue sync). At the end the reindexer **catches up** the shadow: it re-refreshes into the shadow
  every id whose live row has `indexed_at >= build start`, and prunes the shadow's orphans against
  the source (deletes during the build). Then, in one transaction holding an `ACCESS EXCLUSIVE` lock
  on the live table, it repeats the catch-up for the (now tiny) remaining delta and swaps:
  rename live → `__old`, shadow → live name, rename every index and the primary key constraint to
  the live names, drop `__old`. The lock is held only for the delta and the renames.
- plpgsql functions resolve the table by name; the drop of `__old` invalidates cached plans, so the
  live refresh function writes into the new table from the next call.
- One rebuild per index at a time: the reindexer takes `pg_advisory_lock(hashtext('fuzzphony:' ||
  schema || '.' || index))` for the whole run; a second run fails fast with `InvalidArgument`
  ("a rebuild of <index> is already running").
- `ReindexOptions` gains `bool $inPlace = false`: `true` keeps the 0.4 behaviour (upsert into the
  live table, no extra disk). A resumed run (`resumeAfter`) continues filling an existing shadow if
  there is one, else runs in place as today. `fuzzphony:reindex` gets `--in-place`.
- `ReindexResult` gains `bool $swapped`.
- A crashed build leaves the shadow table behind: the next full run drops and restarts it (unless
  resumed with `--from`); the doctor warns "a rebuild of <index> did not finish" with the fix
  `fuzzphony:reindex <index>`.
- Engine SPI (breaking for custom engines): `Engine::beginRebuild(IndexDefinition): void`,
  `refreshShadow(IndexDefinition, array $ids): int`, `finishRebuild(IndexDefinition, \DateTimeImmutable
  $startedAt): void`, `abortRebuild(IndexDefinition): void`. `Reindexer` stays engine-agnostic.
- Disk: a full rebuild needs room for a second copy of the sidecar and its indexes; documented.
- Cost if wrong: medium; the swap is the risky part, so the plan tests it under concurrent writes in
  both sync modes.

### R2 One rebuild job after `TRUNCATE`

- Queue mode: when a `TRUNCATE` hits a watched table and a full resync is needed (joined table,
  query source, or a non-empty source after `TRUNCATE ONLY`), the trigger inserts **one** queue row
  `(index_name, '*')` instead of every document id (`ON CONFLICT DO NOTHING`).
- The worker claims a `'*'` row (`DELETE … RETURNING` with `SKIP LOCKED`) before normal batches and
  runs a full shadow rebuild (R1) for that index; `processQueue` never casts `'*'` to the id type.
  Ids queued for that index before the job are still processed normally (harmless).
- `queueSize` counts it as one item; the doctor's queue check names a pending rebuild job.
- Trigger mode keeps the inline resync (there is no worker to hand a job to); docs say so and
  recommend queue mode for large indexes that watch joined tables.
- The own-table empty fast path (`DELETE FROM` sidecar) is unchanged.
- Cost if wrong: low; a `'*'` doc id can never collide with a real id because ids are cast from the
  queue only after the `'*'` row is filtered out.

### R3 Exact field scoping (layout 2)

- New per-field sidecar columns: `t_<field> tsvector` for every field and `z_<field> text` for every
  fuzzy field, filled by the refresh function next to `tsv` and `fz`. **No extra indexes**: a scoped
  leaf keeps using the existing GIN indexes to find candidates and checks the per-field column on
  the candidate row:
  - full-text: `s.tsv @@ <weighted tsquery> AND s.t_<field> @@ <tsquery>`;
  - typo-tolerant: `(<needle> <% s.fz AND <needle> <% s.z_<field>)`, score
    `word_similarity(<needle>, s.z_<field>)`.
- Unscoped queries are unchanged. An unknown field keeps today's warning and searches everywhere.
- Ranking of a scoped leaf still uses the weighted `tsv`, so scores stay comparable with unscoped
  leaves.
- Storage grows by roughly one extra copy of the indexed text; documented.
- Column names go through `Names`/`Identifier::limit` like the filter columns.
- Cost if wrong: low-medium; the recheck columns can be indexed later if a workload needs it.

### R4 Layout step runner

- `PostgresSchemaGenerator::LAYOUT_VERSION = 2`.
- The runner is SQL, not PHP state: the index plan emits, before its meta upsert, one guarded DO
  block per step whose target version is above the stored one
  (`IF (SELECT layout_version FROM meta WHERE index_name = …) < 2 THEN … END IF`), so the plan
  stays DB-free and `--dump-migration` output contains the steps.
- Step 1 → 2: the new columns come from the normal `ADD COLUMN IF NOT EXISTS`; the step clears
  `documents_hash` for the index, so the doctor asks for a reindex (the per-field columns of old
  documents are empty until then). `Fingerprint::documents` also includes the layout version, so
  documents built for an older layout never match.
- A missing meta table or row (pre-0.4 or never applied) runs no step; the ADD COLUMNs still apply.
- Cost if wrong: low; steps are idempotent and guarded.

### R5 The shared (`*`) row in the doctor

- New doctor check "Shared objects": no `*` row → warning (apply); stored layout older → error
  (apply); newer → error (upgrade Fuzzphony); `definition_hash` ≠ `Fingerprint::shared` (the schema
  or extension schema changed since the last apply) → error (apply).
- It runs once per report, next to "Schema version".

### R6 Partition-aware sync

- For every watched table that is partitioned (`relkind = 'p'`), `schema --apply` also puts the
  `_trn` trigger on **every partition, at every level** (`pg_partition_tree`), through a DO block
  that loops at apply time (the plan stays DB-free). A `TRUNCATE` of one partition then fires the
  watch's sync function, which resyncs like any other watched-table `TRUNCATE` (queue: one rebuild
  job, R2; trigger: inline).
- Statement-level insert/update/delete triggers cannot go on partitions (PostgreSQL rule), so
  direct DML into a partition (bypassing the parent) is not followed at statement level; row-level
  triggers are cloned to partitions automatically. The doctor warns for a partitioned watch with
  `trigger_level: statement` ("writes that target a partition directly are not synced; use
  `trigger_level: row` or write through the parent").
- Doctor: lists the partitions of each watched partitioned table and reports every partition without
  the `_trn` trigger (error, fix `fuzzphony:schema --apply`). A partition attached after the last
  apply is exactly this case.
- `drop()` removes the partition triggers too.
- Cost if wrong: low; the DO block is idempotent (`CREATE OR REPLACE TRIGGER`).

## Public API changes (CHANGELOG Breaking)

- `Engine` gains `beginRebuild()`, `refreshShadow()`, `finishRebuild()`, `abortRebuild()` (custom
  engines must implement them).
- `ReindexOptions` gains `inPlace`; `ReindexResult` gains `swapped`. A full reindex needs disk for a
  second copy of the index while it runs.
- After upgrading: `fuzzphony:schema --apply` (adds the per-field columns and partition triggers,
  bumps the layout), then a full `fuzzphony:reindex` (the doctor asks for it).
- Search results for field-scoped queries change: `brand:x` no longer matches other fields with the
  same weight, and a scoped typo no longer matches another fuzzy field.

## Testing

- Swap: integration tests in both sync modes with writes (insert, update, delete, TRUNCATE) during
  the build — driven by the `onBatch` callback between batches — asserting the final index equals
  a fresh in-place rebuild; a search running during the build sees the old complete index; a crash
  mid-build (exception from `onBatch`) leaves the live index untouched and the doctor warns; a
  second concurrent rebuild fails fast; indexes and constraint carry the live names after the swap.
- `'*'` job: TRUNCATE of a joined table in queue mode queues exactly one row; the worker rebuilds and
  the queue ends empty; normal ids still process.
- Field scoping: unit (exact SQL per compiler) and integration: `brand:x` with two fields sharing a
  weight; `name:sony` no longer returns Sony-brand products; a scoped typo matches only its field.
- Step runner: a layout-1 install (meta row with `layout_version = 1`) gets the columns, the version
  bump and a cleared `documents_hash`; running apply twice is a no-op.
- Partitions: a partitioned watched table (two levels), TRUNCATE of one leaf partition in both sync
  modes; a partition attached after apply is reported by the doctor and fixed by apply; the
  statement-level warning.
- `*` row checks: each branch.
- 100% line coverage; the PR's mutation diff shows no escaped mutants pointing at missing assertions.

## Implementation order (for the plan)

1. Layout step runner + `Fingerprint` layout input + the `*` row doctor check (R4, R5).
2. Per-field columns and exact field scoping (R3), layout 2.
3. Shadow table: generator (table, indexes, shadow refresh function), engine SPI, swap (R1).
4. Reindexer and command on the shadow build, advisory lock, crash handling, doctor warning (R1).
5. The `'*'` rebuild job in queue mode and the worker (R2).
6. Partition triggers and doctor checks (R6).
7. Demo (a field-scoped preset, a note on zero-downtime rebuilds), docs/limitations.md cleanup,
   CHANGELOG, UPGRADE, roadmap.
