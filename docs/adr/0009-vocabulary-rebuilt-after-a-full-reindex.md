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
`Vocabulary::rebuildVocabulary()` collects the words of the live index table into a temporary table
(reading the index, no lock on the vocabulary), then `TRUNCATE`s and refills the vocabulary inside the
same transaction, waiting at most 3 s for its lock. Every full reindex calls it after the documents are
in (the swap or the in-place run), `fuzzphony:reindex --vocabulary` calls it alone. A failure after
a successful full run is reported in `ReindexResult`, it does not undo the run. The engine support is
an optional interface plus a capability, so a custom engine is not broken.

## Consequences

+ Writes pay nothing; a rebuild is one pass over the index (seconds for 500 000 documents).
+ The vocabulary table is never part of the zero-downtime swap, so that machinery is untouched.
− A word that is new since the last full reindex is not known: a correct new word may get a
  suggestion until the next rebuild (the doctor does not know either; schedule `--vocabulary` after
  big imports).
− The `TRUNCATE` blocks a concurrent suggestion lookup for the length of the refill.
