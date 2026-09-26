# Keeping the index in sync

Sync modes, triggers, `TRUNCATE`, reindexing and Messenger. Back to the [README](../README.md).

## Sync modes

| Mode | How | Use when |
|---|---|---|
| `queue` (default) | triggers enqueue ids, `fuzzphony:worker` refreshes in batches | most apps; writes stay fast |
| `trigger` | triggers refresh inside the writing transaction | you need read-your-writes consistency |
| `orm` | Doctrine listener refreshes after `flush()` | no triggers allowed; joined data is not followed |
| `manual` | nothing automatic | batch imports, read-only data |

Set the mode with `#[Searchable(sync: …)]`, the YAML `sync:` key or the builder's `->sync()`.
[ADR 0002](adr/0002-queue-sync-by-default.md) explains why `queue` is the default.

Every `INSERT`, `UPDATE` and `DELETE` on the source table and on watched tables is followed. An
insert adds the document, an update rebuilds it, and a delete removes it: the refresh drops every
indexed id the source no longer returns.

When a change shows up in search depends on the mode:

| Mode | Change visible in search |
|---|---|
| `trigger` | immediately, inside the writing transaction |
| `queue` | once the worker has processed the queue. Until then a deleted row can still appear as a hit; `EntityLoader` skips hits whose row no longer exists, and `fuzzphony:doctor` reports the queue's size and age |
| `orm` | after `flush()`, and only for changes made through the entity manager: raw SQL writes are not seen |
| `manual` | when you call `refresh()` or `reindex()` |

## Watched tables

Triggers also watch joined tables, so renaming a brand reindexes its products:

```php
->watch('brand', 'SELECT id FROM product WHERE brand_id = :id')
```

## Statement-level triggers

Triggers are statement-level by default. They read PostgreSQL transition tables, so
`UPDATE brand SET …` touching 100 000 rows queues all affected documents with one set-based
`INSERT … SELECT` instead of 100 000 trigger calls. `trigger_level: row` switches back; switching
is idempotent and the doctor flags leftovers. See
[ADR 0006](adr/0006-statement-level-triggers.md).

Statement-level triggers cannot be attached to individual partitions: watch the partitioned parent
or use `trigger_level: row` (see [limitations](limitations.md#partitioned-tables)).

## The worker

The worker takes a batch and refreshes it in one statement, so a failure never loses queued ids.
`SKIP LOCKED` lets several workers run side by side. Without long-running processes, run
`fuzzphony:worker --once` from cron.

## Column-aware filtering

Watched tables skip wasted work. A table-sourced index's own watch only refreshes on UPDATEs that
change a mapped field, filter, boost or recency column. No configuration is needed.

Joined-table watches opt in by listing the columns that matter:

```php
->watch('brand', 'SELECT id FROM product WHERE brand_id = :id', columns: ['name'])
// updating any OTHER column of "brand" no longer refreshes dependent products
```

Without `columns`, a joined watch refreshes on every UPDATE. It is opt-in because Fuzzphony cannot
know which of a joined table's columns matter unless you say so.

## TRUNCATE

In `trigger` and `queue` mode a `TRUNCATE` is followed by a separate statement-level
`AFTER TRUNCATE` trigger:

- Truncating a table-sourced index's own table empties the index right away when the source is
  then really empty, and drops its queued ids.
- Truncating any other watched table resyncs every document, because the removed rows can no
  longer tell which documents they belonged to. On a big index this is expensive; see
  [limitations](limitations.md#truncate-on-a-watched-table).

`orm` and `manual` mode never see a `TRUNCATE`: run `fuzzphony:reindex`. Indexes set up with an
older version get the `TRUNCATE` trigger from `fuzzphony:schema --apply`
(`fuzzphony:doctor` reports it missing until then).

## Reindexing and orphan pruning

`fuzzphony:reindex` backfills every row, batched and resumable. A full run (without `--from`)
finishes by removing orphans, indexed documents whose row the source no longer returns, in
batches, and reports how many it removed. A run resumed with `--from` covers only part of the
source, so it never removes anything.

Pruning is relative to what the reindexing session can see. When that session sees fewer rows
than your application (row-level security on the source, a query source using
`current_setting(...)`, a different `search_path` for the CLI user), a full reindex removes the
difference from the index. Pass `--no-prune` (or `prune: false` to `$fuzzphony->reindex()`) there.

A full run whose source returns no row at all does not prune, and says so. That is far more likely
a visibility problem than intent (a real `TRUNCATE` is handled by its trigger). `--prune-empty`
(`pruneEmpty: true`) forces it.

## Messenger (orm mode)

In `orm` mode, refreshing can move out of the request through Symfony Messenger
(`composer require symfony/messenger`). Messenger is optional; the container fails with that hint
when `async` is on without it.

```yaml
fuzzphony:
  orm_sync: { async: true }
framework:
  messenger:
    routing: { Fuzzphony\Bundle\Messenger\RefreshDocuments: async }
```
