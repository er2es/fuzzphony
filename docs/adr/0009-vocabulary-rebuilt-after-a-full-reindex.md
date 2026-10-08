# 9. The vocabulary is rebuilt after a full reindex, never per write

Status: accepted (0.7)

## Context

"Did you mean" (and 0.8's `suggest()`) needs the words of an index and how common each is. Keeping
that per write would put a word-count update into every document refresh (the trigger and queue paths,
`refresh()` of the ORM listener), which is the hot path, and deletions would need reference counting.
The words only matter when a search finds little, and a stale count there costs a slightly worse
suggestion, not a wrong result.

## Decision

`fuzzphony_<index>__vocab (word text PRIMARY KEY, freq integer)` with a trigram index, created
empty by the index's schema plan (additive: the sidecar layout stays 2, nothing forces a reindex).
`Vocabulary::rebuildVocabulary()` runs one transaction: an advisory lock (two rebuilds of an index take
turns), `DELETE` of the old words, `INSERT ... SELECT` of the words of the live index table. Readers
keep the old words until it commits (MVCC), nothing takes an ACCESS EXCLUSIVE lock, and it needs only
`SELECT`, `INSERT` and `DELETE` (no `TRUNCATE`, no `TEMPORARY` privilege); it may join a transaction the
caller has open. Every full reindex calls it after the documents are in (the swap, the in-place run, or a
resumed run that finished the rebuild), `fuzzphony:reindex --vocabulary` calls it alone. A failure after
a successful full run is reported in `ReindexResult`, it does not undo the run. Engine support is an
optional interface, so a custom engine is not broken.

The suggestion reads it without scoping: the vocabulary is the words of the whole index. A
tenant-scoped index therefore never suggests, and the search role needs `SELECT` on it (without, there
is no suggestion, never an error).

## Consequences

+ Writes pay nothing; a rebuild is one pass over the index (about 10 s for 500 000 documents and 150 000 words).
+ The vocabulary table is never part of the zero-downtime swap, so that machinery is untouched.
− A word that is new since the last full reindex is not in the vocabulary, but the suggestion also
  asks the index itself, so such a word is not "corrected"; a close word it would be suggested is missing.
− `DELETE` leaves dead rows (autovacuum), a few hundred thousand at most per rebuild.
− A vocabulary is not scoped by filters: see above.
