# Console commands

The `fuzzphony:*` commands, the doctor and the configuration wizard. Back to the
[README](../README.md).

## Commands

| Command | Purpose |
|---|---|
| `fuzzphony:schema [index] [--apply\|--drop\|--dump-migration=dir] [--force]` | show / apply / export idempotent DDL (alias `fuzzphony:install`); `--dump-migration` writes a Doctrine migration, see [Doctrine Migrations](integrations.md#doctrine-migrations) |
| `fuzzphony:reindex [index] [--batch=5000] [--from=id] [--in-place] [--no-prune] [--prune-empty] [--force]` | rebuild next to the live index and swap it in (zero downtime; `--in-place` writes the live index directly), resumable, with progress; a failed run exits with code 1 and prints the command to resume with, and the command stops at the first index that fails; see [Reindexing](sync.md#reindexing-and-orphan-pruning) |
| `fuzzphony:worker [--once] [--time-limit=s] [--index=x]` | drain the sync queue, including the full rebuilds a `TRUNCATE` queued (a failed one is retried after a back-off; `--once` then exits with code 1); graceful on SIGTERM |
| `fuzzphony:doctor [index] [--deep] [--strict]` | health check with fixes |
| `fuzzphony:search index 'query' [-w filter] [--explain [--analyze]]` | try queries, see score breakdowns, SQL and plans |
| `fuzzphony:wizard [table] [--format=yaml\|builder\|attributes] [--write=file] [--try]` | suggest, explain and export a definition |

`fuzzphony:schema` without `--apply` only prints the SQL, so you can review it first. See
[Reindexing and orphan pruning](sync.md#reindexing-and-orphan-pruning) for what `--no-prune` and
`--prune-empty` are for.

`fuzzphony:schema --drop --apply` and `fuzzphony:reindex --prune-empty` are destructive (the
former removes the sidecar table, its triggers, functions and queued rows; the latter can wipe
every indexed document). Both explain what will happen and ask for confirmation
(default **no**); `--force` skips the question, and is required to run either non-interactively
(`--no-interaction`) -- without it they refuse with exit code 1. The PHP API
(`Fuzzphony::schema()`, `Fuzzphony::reindex()`) never prompts.

## The doctor

`fuzzphony:doctor` compares the database with the definitions and prints a fix for every problem.
The exit code is non-zero on errors (with `--strict`, also on warnings), so it belongs in CI.

```
 Index "products"
  ✔ PostgreSQL version       16.4
  ✔ Extension pg_trgm        installed
  ✔ Source                   custom query
  ✔ Filter price             price (integer)
  ✘ Sidecar columns          Schema drift, missing: f_in_stock.
  ✘ Index fuzzphony_products_fz   INVALID (an interrupted concurrent build)
  ! Sync queue               12840 item(s) waiting, oldest 900s: is the worker running?
  ✔ Coverage                 999 812 of 1 000 000 documents indexed (100.0%, estimated)

 Fix for "Sidecar columns": bin/console fuzzphony:schema --apply
 Fix for "Sync queue": bin/console fuzzphony:worker   (or from cron: bin/console fuzzphony:worker --once)
```

It checks:

- server version, extensions, text configuration and helper functions (looked up in Fuzzphony's
  schema);
- that the source can be queried;
- id, field, filter, boost and recency column mapping and types, and the source key;
- sidecar column drift, and missing or INVALID indexes;
- objects left in `public` after switching to a dedicated `schema` (a warning when the configured
  schema has no sidecar table for an index but `public` still has one);
- with DoctrineBundle, an application `schema_filter` that still lets Fuzzphony's tables through,
  so `migrations:diff` would drop them (a warning with the regex to merge; none once your filter
  hides them);
- missing or disabled triggers, including the `TRUNCATE` trigger, which an index set up with an
  older version lacks until `fuzzphony:schema --apply` runs again;
- partitioned watched tables: every partition carries the `TRUNCATE` trigger (one attached after
  the last apply does not), and a warning at `trigger_level: statement`, where a write that targets
  a partition directly is not synced;
- queue backlog and age, a pending full-rebuild job a `TRUNCATE` queued, and whether the worker's
  rebuild of it keeps failing (the last error, how often, when);
- a full reindex that did not finish (its rebuild table, change log or change log trigger is left
  over, so every change keeps being logged; fixed by resuming it with `--from` or by a full
  `fuzzphony:reindex`; an error when the trigger is left without its log, which fails every write
  to the index), or one that is running;
- coverage (estimated, or exact with `--deep`);
- orphaned documents (with `--deep`; fixed by `fuzzphony:reindex`);
- risky thresholds;
- the shared objects' version row (`*` in `fuzzphony_meta`): a warning when it is missing, an error
  when its layout is older or newer than this library's, or when the schema or extension schema
  changed since the last apply;
- the schema version: which layout and definition the index was last applied with (an error when
  the definition changed since, or the layout is older or newer than this library's) and whether
  the documents were built from the current definition (a warning until a full
  `fuzzphony:reindex` records it). The role running the doctor needs `SELECT` on `fuzzphony_meta`;
  without it this check is a warning with the `GRANT` to run.

## The configuration wizard

`fuzzphony:wizard [table]` (or the demo's web wizard) reads the table's structure and planner
statistics and suggests a complete definition, explaining every decision:

```
 Column         Role                                Why
 name           field A, fuzzy                      looks like the title: most important, typo tolerant
 description    field D                             long text: searchable, but weighted lowest
 status         filter                              only ~4 distinct values: better as a filter
 password_hash  skip                                looks sensitive; never indexed automatically
 brand_id       join brand.name -> field brand      related label is searchable; changes in that table are watched
 popularity     boost                               popularity-like number: used by the "popular" ranking profile
 published_at   recency                             newest first in the "popular" ranking profile
```

Output it as YAML, builder code or attributes (`--format`), write it to a file (`--write`), or
`--try` it on the spot: schema, reindex, doctor and interactive searches.

Boost weights are scaled from the column's statistics, so the most popular document gets a bonus
comparable to a good text match. Tables that were never analysed are sampled instead.

The wizard never edits your configuration files. `--try` creates a throw-away index, and the
exported definition is code you own
([ADR 0007](adr/0007-wizard-suggests-never-applies.md)).
