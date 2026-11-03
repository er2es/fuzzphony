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

<!-- fragment -->
```php
->watch('brand', 'SELECT id FROM product WHERE brand_id = :id')
```

### The doctor finds the tables you forgot to watch

A joined table nobody watches is the quiet way to go stale: the index stays healthy, only its documents are old. `fuzzphony:doctor` lists the tables the source query reads
(from PostgreSQL's dependency catalog, not by parsing your SQL; a view is followed down to its tables) and warns about each one that has no watch:

```
! Watches   the source query reads "address" but no watch covers it: a change there does not update the index.
Fix for "Watches": add a watch (adapt the ids query: ...): watch: { address: 'SELECT id FROM customer WHERE <fk> = :id' }   or set sync: manual
```

The ids query is a template: Fuzzphony does not guess the join condition. A watched partitioned parent covers its partitions, `sync: manual` is not checked, and when the
check cannot run it says `not checked: <reason>` instead of failing. `bin/console fuzzphony:doctor --fail-on=warning` fails a pipeline on it.

## Statement-level triggers

Triggers are statement-level by default. They read PostgreSQL transition tables, so
`UPDATE brand SET …` touching 100 000 rows queues all affected documents with one set-based
`INSERT … SELECT` instead of 100 000 trigger calls. `trigger_level: row` switches back; switching
is idempotent and the doctor flags leftovers. See
[ADR 0006](adr/0006-statement-level-triggers.md).

Statement-level triggers cannot be attached to individual partitions (a PostgreSQL rule): see
[Partitioned tables](#partitioned-tables).

## One trigger per table, one change log

With the defaults (`sync: queue`, statement-level triggers) a watched table has ONE set of triggers
(`fuzzphony_watch__<table>_<hash>__ins|upd|del|trn`; the hash keeps `public.x` and `public_x` apart), however many indexes watch it. A statement appends the key of
every changed row to one shared log (`fuzzphony_watch_log`; for an `UPDATE` also which of the watched columns
changed), so the write costs one `INSERT` whatever the number of indexes. Each index keeps its own position in
the log (`fuzzphony_watches`) and turns the entries after it into queue rows with its own watch query when
the worker takes a batch (`fuzzphony_drain_<index>()`): changes are neither lost nor processed twice, an index
whose worker is stopped simply reads on from where it was, and a new index starts at the end of the log (it
needs the first `fuzzphony:reindex` as before).

- An entry is read once its transaction has ended and no writing transaction that started earlier is still open, so a
  transaction that commits late is never skipped. Any open writing transaction of the **cluster** (an import in one
  transaction, a forgotten `BEGIN` after a write, a prepared transaction nobody committed: see `pg_prepared_xacts`) holds the drain back,
  and with it the sync of every index, until it ends.
- `INSERT` on `fuzzphony_watch_log` is a trust boundary: the role that writes the watched tables must be trusted
  with it (any role that can insert there can write keys and TRUNCATE entries). A key that does not fit the source
  key (a forged or buggy writer) cannot stop the sync: the drain retries the batch key by key, skips the unusable
  keys (they can never succeed) and counts them per watch in `fuzzphony_watches.bad_rows`; the doctor says "N log
  row(s) for <table> could not be applied" (never the value). Forged TRUNCATE entries queue one full rebuild per
  index at most (the queue holds a single `*` row per index).
- Dropping an index (`fuzzphony:schema <index> --drop --apply`) removes its part only; the triggers and the log
  entries of a table go with the last index that watches it.
- The role that writes to a watched table needs `INSERT` on `fuzzphony_watch_log` (the trigger runs as that role);
  the worker's role needs `SELECT`, `UPDATE`, `DELETE` on it (`DELETE ... FOR UPDATE SKIP LOCKED` needs `UPDATE` too), `SELECT` and `UPDATE (cur_xid)` (a column privilege, nothing more) on `fuzzphony_watches`, and the usual
  rights on the queue. `fuzzphony:schema --dump-grants` prints all of them ([Database roles](roles.md)); every function, the drain function included, is `SECURITY INVOKER`.
- `sync: trigger` and `trigger_level: row` keep a trigger set of their own per index and table: their refresh
  must run inside the writing statement.
- A `TRUNCATE` is logged like a change and acted on when the worker drains the log (see [TRUNCATE](#truncate)).

## A database where you cannot create triggers

`queue` and `trigger` sync need triggers on every watched table, and PostgreSQL lets only the table's owner (or a role holding its `TRIGGER` privilege) create them. As a role without that right
`fuzzphony:schema --apply` stops with a message naming the table and exits non-zero; nothing is applied (the apply is one transaction), so the doctor then reports every object as missing.
Either run the apply once as the table owner (or `GRANT TRIGGER ON the_table TO the_role`), or use `sync: manual`: it needs no triggers, you call `refresh()` after a change
or run `fuzzphony:reindex` (see [Reindexing](#reindexing-and-orphan-pruning)).

## The worker

The worker moves the shared change log into the index queue, takes a batch and refreshes it in one statement,
so a failure never loses queued ids. `SKIP LOCKED` lets several workers run side by side. Without long-running
processes, run `fuzzphony:worker --once` from cron.

**A document that cannot be built.** When a batch fails on the data (a cast that fails on one row, a value
too big for the index: SQLSTATE class 22, 23, 54 or a raised error), the worker retries it in halves down to single ids,
refreshes everything that can be refreshed and keeps only the failing ids queued, each with its attempt count and the
error class and SQLSTATE. A failing id is tried again after a back-off (60 s, doubling to an hour); fixing the source row
clears it at the next try. The doctor's "Sync queue" check names the failing ids (never the row values), and the
worker prints only the exception class and SQLSTATE: the driver's message can contain the source row. Other
failures (a lost connection, a missing function) still stop the index's queue for the back-off and are reported by
`fuzzphony:worker`.

## Column-aware filtering

Watched tables skip wasted work. A table-sourced index's own watch only refreshes on UPDATEs that
change a mapped field, filter, boost or recency column. No configuration is needed.

Joined-table watches opt in by listing the columns that matter:

<!-- fragment -->
```php
->watch('brand', 'SELECT id FROM product WHERE brand_id = :id', columns: ['name'])
// updating any OTHER column of "brand" no longer refreshes dependent products
```

Without `columns`, a joined watch refreshes on every UPDATE. It is opt-in because Fuzzphony cannot
know which of a joined table's columns matter unless you say so.

## TRUNCATE

In `trigger` and `queue` mode a `TRUNCATE` is followed by a separate statement-level
`AFTER TRUNCATE` trigger:

- Truncating a table-sourced index's own table empties the index when the source is then really
  empty (in `queue` mode at the worker's next drain of the change log, in `trigger` mode inside the
  `TRUNCATE`), and drops its queued ids. A `TRUNCATE` never waits for the queue rows a running worker holds.
- Truncating any other watched table (a joined table, a query source's tables), or a source that
  still returns rows afterwards (`TRUNCATE ONLY` on a table-inheritance parent), needs a full
  resync: the removed rows can no longer tell which documents they belonged to.
  - In `queue` mode one full-rebuild job is queued (by the trigger at row level, by the worker's drain of the change log at statement
    level), so the `TRUNCATE` itself stays cheap. The worker runs it before the queued ids, as a zero-downtime rebuild (see
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

## Several sources in one index

An index with [sources](configuration.md#several-sources-in-one-index) syncs per source. Every watch belongs to a source (the shared registry
`fuzzphony_watches` records it in its `source` column), and a changed key goes through that source's own `ids` query into queue rows
carrying the source's key (`product:42`). Two sources, or two indexes, that watch the same table each map the key their own way, and
a change of the table refreshes both. `sync: trigger`, `queue`, `manual` and `orm` keep their meaning for the index as a whole; with
`orm`, an entity is mapped to the source whose `entity:` it is.

- A refresh rewrites only the rows it names: a changed product leaves the article rows alone.
- A `TRUNCATE` of a table one source reads queues a rebuild of **that source only** (queue row `*:<source>`); the worker runs it in place,
  keeping the rows of the other sources. Truncating the source's own table empties that source's rows (`kind`), not the index.
- `fuzzphony:reindex <index>` rebuilds all sources into the `__next` table and swaps, as for any index. `fuzzphony:reindex <index> --source=<name>`
  deletes the rows that source no longer returns and rewrites its rows in place (no swap, the other sources untouched) and reports the
  documents per source. It takes the index's rebuild lock, so it does not run while a full reindex does.

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
["did you mean"](searching.md#did-you-mean) reads) is rebuilt from the new index: one transaction
deletes the old words and inserts the new ones, so a reader keeps the old words until it commits and no
lock is held against it (rebuilds of one index take turns through an advisory lock). It may join a
transaction you have open. A vocabulary that cannot be rebuilt (a missing privilege or table) does not
undo the reindex: the command says so, and `fuzzphony:reindex --vocabulary` (or
`Fuzzphony::rebuildVocabulary()`) repeats just that step. It is rebuilt after a full run and after a
resumed run that finished the rebuild and swapped it in; a resumed in-place run, an empty source and
`ReindexOptions(vocabulary: false)` leave it alone.

Nothing else keeps it current: single document changes never touch it. Schedule `fuzzphony:reindex
--vocabulary` (a nightly cron job is plenty) for an index whose words change, see
[Did you mean](searching.md#did-you-mean).

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
