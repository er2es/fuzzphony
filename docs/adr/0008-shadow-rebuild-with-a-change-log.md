# 8. Zero-downtime reindex: a shadow table caught up from a change log

Status: accepted (0.5)

## Context
A full reindex upserted into the live index table: while it ran, searches saw a mix of old and
new documents, and orphans stayed findable until the final prune. Building a second table and
swapping it in fixes that, but changes made to the source while the second table is built must
reach it too. Catching up by `indexed_at` (the rows the live table refreshed since the build
started) needs a scan of the whole live table (the column has no index), once more under the swap
lock; misses deletions made after the last orphan prune; misses writes of transactions that
started before the threshold (`now()` is the transaction start); and a resumed build would need
its start time stored somewhere.

## Decision
`beginRebuild()` creates `fuzzphony_<index>__next` (the current layout), a change log
`fuzzphony_<index>__changes (id PRIMARY KEY)` and a row trigger on the live table that logs the id
of every document written or deleted there, in the writer's own transaction. The live refresh
function also logs every id it is given while the log exists (after its own writes, so it takes
the live table's lock first, like the swap): a document the live table never had (queued, or not
refreshed yet in manual sync, when the rebuild loaded it, and deleted since) writes nothing there,
so the trigger alone would miss it. For the same reason, truncating a table source keeps the
index's queue while a rebuild runs (the worker's refreshes log those ids). The reindex fills the
rebuild through a second static refresh function. `finishRebuild()` builds the rebuild's indexes,
refreshes logged ids into it in batches (each batch taken and refreshed in one statement), then,
in one transaction, takes `ACCESS EXCLUSIVE` on the live table (this waits for every transaction
that wrote it, so the log is complete), refreshes the remaining logged ids, copies grants and owner,
drops the live table and renames the rebuild, its primary key and indexes to the live names. A
session-level advisory lock allows one rebuild per index. A role that cannot do the DDL, and a
reindex with `prune: false`, run in place as before.

## Consequences
+ Searches never see a half-built index; the lock is held for the last few logged ids and the renames.
+ Deletions are caught up like any change; a crashed rebuild keeps logging, so `--from` can resume it.
− A second copy of the index on disk while it runs; a row trigger on the live table during a rebuild.
− The swap waits for the transactions that hold the live table (a long writer, a running
  autovacuum, which PostgreSQL cancels after `deadlock_timeout`); searches and writes queue behind
  it meanwhile.
− The reindexing role needs `CREATE` on Fuzzphony's schema and ownership of the index table, and a
  session connection (the advisory lock).
