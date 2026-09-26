# Known limitations

What Fuzzphony does not do well yet, and the planned fix for each. Back to the
[README](../README.md).

Each of these has a planned fix on the [roadmap](roadmap.md), except the partition trigger rule,
which PostgreSQL imposes.

## Field scoping works per weight group

Field-scoped queries (`brand:x`) search every field that shares the scoped field's weight, not only
that field.

Planned fix: [exact field scoping](roadmap.md#exact-field-scoping).

## Field scoping is exact-only on the typo-tolerant side

The fuzzy fields are stored as one trigram-indexed text. So a scoped word that is not found exactly
may match any fuzzy field once the typo-tolerant branch runs. On the demo catalogue `name:sony`
finds no product with "sony" in its name, falls back to typo tolerance and returns Sony-brand
products. `name:kettel` (a typo) still finds kettles.

Planned fix: per-field trigram columns, [exact field scoping](roadmap.md#exact-field-scoping).

## Typo tolerance is lenient

Typo tolerance is per word and deliberately lenient. At the default `fuzzy_similarity` of 0.3 a
correctly spelled word also matches similar words (`mouse` is trigram-close to `monitor` and
`mower`), so `wireles mouse` also lists wireless monitors, ranked below the mice. With very
frequent words these near-misses can use up `candidate_limit` before ranking. Raise
`fuzzy_similarity` (0.4 to 0.5 is stricter) or `candidate_limit` when that matters.

Planned fix: [length-aware typo tolerance](roadmap.md#length-aware-typo-tolerance).

## Partitioned tables

Statement-level triggers cannot be attached to individual partitions (a PostgreSQL rule). Watch
the partitioned parent, or use `trigger_level: row`.

The `TRUNCATE` trigger on a partitioned parent fires when the parent is truncated, but not when a
single partition is truncated directly (`TRUNCATE product_2024`). Run `fuzzphony:reindex`
afterwards; it also removes the orphaned documents.

Planned fix: [partition-aware sync](roadmap.md#partition-aware-sync).

## TRUNCATE on a watched table

`TRUNCATE` is followed in `trigger` and `queue` mode (see [TRUNCATE](sync.md#truncate)).

Truncating the index's own source table is cheap. The sidecar is emptied, but only after checking
that the source really is empty. A `TRUNCATE ONLY` on a table-inheritance parent leaves the child
tables' rows in the source, so it resyncs like the case below.

Truncating a joined or otherwise watched table is not cheap. Every indexed document, plus every
document the source returns now, is resynced: in `trigger` mode inside the truncating transaction,
in `queue` mode by queueing all those ids for the worker. Measured on a 1M-document index:

| Mode | `TRUNCATE` took | Side effects |
|---|---|---|
| `trigger` | 42.8 s | holds an `ACCESS EXCLUSIVE` lock on the truncated table and row locks on the sidecar the whole time |
| `queue` | 8.5 s | plus 1M queued ids |

For big indexes that watch joined tables, prefer `queue` sync, or truncate in a maintenance window.
A query source's main table counts as a watched table here, since Fuzzphony cannot tell that the
source is now empty.

In `queue` mode a `TRUNCATE` never waits for the queue rows a running worker holds when it empties
the index. The joined-table resync can still wait for a conflicting row the worker holds. In the
worst case a deadlock makes the `TRUNCATE` fail (the window is a few microseconds per worker
batch): retry it, the queue keeps its ids.

Truncating a single partition of a partitioned source fires nothing (see
[Partitioned tables](#partitioned-tables)). Indexes set up with an older version get the `TRUNCATE`
trigger from `fuzzphony:schema --apply` (`fuzzphony:doctor` reports it missing until then). `orm`
and `manual` mode never see a `TRUNCATE`: run `fuzzphony:reindex`.

Planned fix: [zero-downtime reindex](roadmap.md#zero-downtime-reindex) queues one full-resync job
instead of every document id.

## Ranking is approximate beyond `candidate_limit`

With very frequent words, ranking considers the first `candidate_limit` matches, so ordering is
approximate beyond them, and `total` is reported as a lower bound (`2000+`).

Planned fix: an opt-in exact `total`, part of [facets](roadmap.md#facets).
