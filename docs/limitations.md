# Known limitations

What Fuzzphony does not do well yet, and the planned fix for each. Back to the
[README](../README.md).

Each of these has a planned fix on the [roadmap](roadmap.md), except attaching and detaching
partitions, which fire no triggers in PostgreSQL, and the trigram limits of typo tolerance.

## Typo tolerance is trigram-based

Typo tolerance is per word, and the similarity a word needs is proportional to its length (see
[Typo tolerance](searching.md#typo-tolerance)). Trigrams cannot tell a typo from a different word that
looks alike: `cable` and `table` are equally close, and `mose` is as close to `monitor` and `mower` as
to `mouse` (one letter each), so a search for `mose` also lists them, ranked below the mice. That is
the price of tolerating a typo in a four-letter word; a flat `fuzzy_similarity` (a number, for every
word) trades the other way, and the fuzzy search only runs when exact matching finds fewer than
`fallback_below` hits.

## Attaching or detaching a partition is not followed

`ALTER TABLE … ATTACH PARTITION` and `DETACH PARTITION` fire no triggers: attached rows are not
indexed and detached rows stay in the index. Run `fuzzphony:reindex <index>` afterwards; it also
removes the orphaned documents. See [partitioned tables](sync.md#partitioned-tables).

## Ranking is approximate beyond `candidate_limit`

With very frequent words, ranking considers the first `candidate_limit` matches, so ordering is
approximate beyond them, and `total` is reported as a lower bound (`2000+`).

Planned fix: an opt-in exact `total`, part of [facets](roadmap.md#facets).
