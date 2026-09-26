# Console commands

The `fuzzphony:*` commands, the doctor and the configuration wizard. Back to the
[README](../README.md).

## Commands

| Command | Purpose |
|---|---|
| `fuzzphony:schema [index] [--apply\|--drop\|--dump-migration=dir]` | show / apply / export idempotent DDL (alias `fuzzphony:install`) |
| `fuzzphony:reindex [index] [--batch=5000] [--from=id] [--no-prune] [--prune-empty]` | resumable backfill with progress; a full run also removes orphaned documents (`--no-prune` keeps them; an empty source is only pruned with `--prune-empty`) |
| `fuzzphony:worker [--once] [--time-limit=s] [--index=x]` | drain the sync queue; graceful on SIGTERM |
| `fuzzphony:doctor [index] [--deep] [--strict]` | health check with fixes |
| `fuzzphony:search index 'query' [-w filter] [--explain [--analyze]]` | try queries, see score breakdowns, SQL and plans |
| `fuzzphony:wizard [table] [--format=yaml\|builder\|attributes] [--write=file] [--try]` | suggest, explain and export a definition |

`fuzzphony:schema` without `--apply` only prints the SQL, so you can review it first. See
[Reindexing and orphan pruning](sync.md#reindexing-and-orphan-pruning) for what `--no-prune` and
`--prune-empty` are for.

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

- server version, extensions, text configuration and helper functions;
- that the source can be queried;
- id, field, filter, boost and recency column mapping and types, and the source key;
- sidecar column drift, and missing or INVALID indexes;
- missing or disabled triggers, including the `TRUNCATE` trigger, which an index set up with an
  older version lacks until `fuzzphony:schema --apply` runs again;
- queue backlog and age;
- coverage (estimated, or exact with `--deep`);
- orphaned documents (with `--deep`; fixed by `fuzzphony:reindex`);
- risky thresholds.

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
