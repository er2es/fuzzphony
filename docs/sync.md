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

Statement-level triggers cannot be attached to individual partitions (a PostgreSQL rule): see
[Partitioned tables](#partitioned-tables).

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
  then really empty, and drops its queued ids. A `TRUNCATE` never waits for the queue rows a
  running worker holds.
- Truncating any other watched table (a joined table, a query source's tables), or a source that
  still returns rows afterwards (`TRUNCATE ONLY` on a table-inheritance parent), needs a full
  resync: the removed rows can no longer tell which documents they belonged to.
  - In `queue` mode the trigger queues one full-rebuild job, so the `TRUNCATE` itself stays cheap.
    The worker runs it before the queued ids, as a zero-downtime rebuild (see
    [Reindexing](#reindexing-and-orphan-pruning)); a worker role that cannot build next to the live
    index runs it in place. Like `fuzzphony:reindex`, the rebuild reads the source in the worker's
    session, so that session must see the source tables on its `search_path`. The doctor's queue
    check names a pending job.

    The job is the queue row `(index_name, '*')`; a string document id `*` is therefore reserved
    (a change to such a document would queue a full rebuild, and the worker never refreshes a
    document with that id). The job stays queued until a full run that started after it succeeds:
    the worker's rebuild, or any full `fuzzphony:reindex` (`--in-place` included, not a resumed or
    `--no-prune` one). A run that fails or is killed keeps it, and a `TRUNCATE` while a run is
    going moves the job's time on, so it stays for the next run.

    A rebuild runs inside the worker's cycle, so it delays the sync of the other indexes that
    worker serves; for big indexes run one worker per index (`fuzzphony:worker --index=<name>`).
    A rebuild that fails never stops the worker: it writes the error to stderr, keeps the job, syncs
    the index's queued ids and the other indexes as usual, and tries the job again after 1 minute,
    doubling up to 1 hour. `fuzzphony:worker --once` processes everything else and exits with code
    1. The doctor's queue check warns "a full rebuild keeps failing" with the last error until a
    full run succeeds.
  - In `trigger` mode there is no worker to hand the job to: every indexed document, plus every
    document the source now returns, is resynced inside the truncating transaction. On a
    1M-document index that took 42.8 s, holding an `ACCESS EXCLUSIVE` lock on the truncated table
    and row locks on the index the whole time. Prefer `queue` mode for large indexes that watch
    joined tables, or truncate in a maintenance window.

`orm` and `manual` mode never see a `TRUNCATE`: run `fuzzphony:reindex`. Indexes set up with an
older version get the `TRUNCATE` trigger from `fuzzphony:schema --apply`
(`fuzzphony:doctor` reports it missing until then).

## Partitioned tables

Watch the partitioned parent. Writes through the parent are synced at both trigger levels.
PostgreSQL clones row-level triggers to every partition, so with `trigger_level: row` a write that
targets a partition directly (`INSERT INTO product_2024 …`) is synced too. Statement-level triggers
cannot go on partitions, so with the default `trigger_level: statement` such a write is not synced
(the doctor warns): write through the parent, or use `trigger_level: row`.

`fuzzphony:schema --apply` puts the `TRUNCATE` trigger on every partition, at every level, so
truncating a single partition (`TRUNCATE product_2024`) is followed like any `TRUNCATE` of a
watched table (see [TRUNCATE](#truncate)). A partition attached after the last apply has no such
trigger yet: the doctor lists it, and `fuzzphony:schema --apply` adds it. Truncating the parent
fires the trigger of the parent and of every partition. For a table-sourced index's own table
that empties the index as usual; for any other watched table it is still one rebuild job in
`queue` mode, but one resync per partition in `trigger` mode.

`ALTER TABLE … ATTACH PARTITION` and `DETACH PARTITION` fire no triggers (a PostgreSQL rule): the
rows of an attached table are not indexed, and the rows of a detached one stay in the index. Run
`fuzzphony:reindex <index>` afterwards; it also removes the orphaned documents. A detached
partition keeps its `TRUNCATE` trigger, so truncating it would still resync the index: the doctor
warns, and `fuzzphony:schema --apply` (or `--drop --apply`) removes it.

## Reindexing and orphan pruning

`fuzzphony:reindex` rebuilds every document, in batches, with progress, resumable.

A full run builds the new index next to the live one (`fuzzphony_<index>__next`) and swaps it in
at the end: searches read the complete old index until then, never a half-built one. Changes made
meanwhile reach the live index as usual (trigger or queue sync) and are logged; the reindex catches
up with them, then swaps inside one short transaction that holds an `ACCESS EXCLUSIVE` lock on the
live table (writers and searches wait for the last few logged changes and the renames). Documents
the source no longer returns (orphans) go with the old table. The index keeps its grants and owner.

A full run needs room for a second copy of the index while it runs, a role that can create tables
in Fuzzphony's schema and owns the index table (or is a member of its owner), and a session
connection (it holds an advisory lock: not a transaction-pooling PgBouncer). Behind one, the lock
can be released on another server connection than the one that took it: PostgreSQL only warns, the
lock stays held there, and later reindexes fail with "already running" (and
`fuzzphony:schema --apply` refuses) until the pooler closes that connection; reindex with
`--in-place` there. One rebuild per index runs at a time; a second one fails right away. A role without those rights, or an install where
`fuzzphony:schema --apply` has not run since the upgrade, reindexes in place, and
`fuzzphony:reindex` says so.

After a full run, a fuzzy index's vocabulary (its words and in how many documents each occurs, which
["did you mean"](searching.md#did-you-mean) reads) is rebuilt from the new index in one transaction:
the vocabulary table is locked only for the swap of its words (at most 3 s of waiting), and only a
search that asks for a suggestion reads it. A vocabulary that cannot be rebuilt (a missing privilege or
table) does not undo the reindex: the command says so, and `fuzzphony:reindex --vocabulary` repeats
just that step. Resumed runs and runs without pruning leave it alone; so does `ReindexOptions(vocabulary: false)`.

`--in-place` (`new ReindexOptions(inPlace: true)`) writes the live index directly, as before 0.5:
no second copy, but searches see a mix of old and new documents while it runs. It finishes by
removing orphans in batches and reports how many. A full `--in-place` run first discards a rebuild
a failed run left behind (in one transaction, without a session lock, so it works behind a
transaction-pooling PgBouncer; a running rebuild is left alone), and a failed in-place run is resumed in place: the hints it prints read
`--in-place --from=…` (or `--no-prune --from=…`).

A run that fails or is killed leaves its rebuild behind, and every change to the live index is
logged for it: `fuzzphony:doctor` warns ("a rebuild of … did not finish"). Resume it with `--from`
(the last id it printed), or run a full reindex, which starts over. `--from` without a leftover
rebuild writes in place and covers only part of the source, so it never removes anything.

What a full run drops is relative to what the reindexing session can see. When that session sees
fewer rows than your application (row-level security on the source, a query source using
`current_setting(...)`, a different `search_path` for the CLI user), the difference disappears
from the index. Pass `--no-prune` (or `new ReindexOptions(prune: false)` to
`$fuzzphony->reindex()`) there; it always writes in place.

A full run whose source returns no row at all keeps the index, and says so
(`ReindexResult::$pruneSkippedEmptySource`). That is far more likely a visibility problem than
intent (a real `TRUNCATE` is handled by its trigger). `--prune-empty`
(`new ReindexOptions(pruneEmpty: true)`) empties the index anyway: because it can wipe every
indexed document, `fuzzphony:reindex --prune-empty` explains that and asks for confirmation first
(default no); `--force` skips the question and is required to use `--prune-empty`
non-interactively. The PHP API (`ReindexOptions(pruneEmpty: true)`) never asks.

Call `Fuzzphony::reindex()` outside a transaction: the run commits batch by batch and swaps in a
short transaction of its own. Inside your transaction the batches and the swap's lock would join
it (searches blocked until you commit), so there it writes in place (`ReindexResult::$swapped` is
false).

`fuzzphony:schema --apply` (and `--drop --apply`) refuses to run while a full rebuild of an index
in its plan is building, a `fuzzphony:reindex` or the worker's rebuild of a job a `TRUNCATE` queued
(it would replace the functions the rebuild uses): run it again when the rebuild has finished (a
deploy pipeline that applies the schema should retry). A reindex started while an apply runs fails right away with "already
running". A `--dump-migration` migration contains the same guard, but it is not transactional, so
it only refuses while a rebuild is running at the guard statement.

When the swap cannot lock the live table (long transactions or autovacuum hold it) it retries 5
times, 3 s each; then the run fails, keeps its rebuild, and `fuzzphony:reindex` exits with code 1
and prints the `--from` to resume with. Starting a rebuild (its lock conflicts with every writer's)
and discarding one (dropping its change log trigger conflicts with every search) wait at most 3 s
too, so they never queue the index's writes or searches behind a long transaction: a start that
times out fails and changes nothing (run it again); a discard that times out fails and leaves the
rebuild behind (the doctor reports it, the next full run replaces it).

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
