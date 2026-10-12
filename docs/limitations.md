# Known limitations

What Fuzzphony does not do well yet, and the planned fix for each. Back to the
[README](../README.md).

Each of these has a planned fix on the [roadmap](roadmap.md), except attaching and detaching
partitions, which fire no triggers in PostgreSQL, the trigram limits of typo tolerance, and the
best-effort search log.

## Typo tolerance is trigram-based

Typo tolerance is per word, and the similarity a word needs is proportional to its length (see
[Typo tolerance](searching.md#typo-tolerance)). Trigrams cannot tell a typo from a different word that
looks alike: `cable` and `table` are equally close, and `mose` is as close to `monitor` and `mower` as
to `mouse` (0.40 each), so a search for `mose` also lists them, ranked below the mice. Because the
threshold keeps a different word at the worst case of a typo out (`mouse` / `monitor`), a letter
replaced in the middle of a 5 to 7 letter word, or two letters swapped, may not be tolerated
(`mpuse`, `mosue`; the same swap at the end of a word, `torhc`, is). A flat `fuzzy_similarity` (a number, for every word) trades the other way, and the fuzzy
search only runs when exact matching finds fewer than `fallback_below` hits.

## Attaching or detaching a partition is not followed

`ALTER TABLE … ATTACH PARTITION` and `DETACH PARTITION` fire no triggers: attached rows are not
indexed and detached rows stay in the index. Run `fuzzphony:reindex <index>` afterwards; it also
removes the orphaned documents. See [partitioned tables](sync.md#partitioned-tables).

## Ranking is approximate beyond `candidate_limit`

With very frequent words, ranking considers the first `candidate_limit` matches, so ordering is
approximate beyond them, and `total` is reported as a lower bound (`2000+`).

Planned fix: an opt-in exact `total`, part of [facets](roadmap.md#facets).

## The search log is best-effort

The [search log](analytics.md) is for trends, not an audit trail. Its rows are written in one batch after the
response; a write that fails (the table is missing, the database is down) is counted in
`fuzzphony.search_log.failed`, logged and dropped, never retried. A long-running worker writes its buffer every
1000 records and on every container reset (between two messages or requests), but a process killed with `SIGKILL`
loses the (at most 1000) records it still holds. The `hits` of a search that hit `candidate_limit` is a lower bound (see above).
Redaction removes what a regular expression can describe, nothing more, and the live search of the Live Component
is not logged unless you ask for it. A log you must not lose is a custom `SearchLog` that writes somewhere durable,
which the built-in reports then do not read.
