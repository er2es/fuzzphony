# Known limitations

What Fuzzphony does not do well yet, and the planned fix for each. Back to the
[README](../README.md).

Each of these has a planned fix on the [roadmap](roadmap.md), except attaching and detaching
partitions, which fire no triggers in PostgreSQL, and the trigram limits of typo tolerance.

## Typo tolerance is trigram-based

Typo tolerance is per word, and the similarity a word needs depends on its length (see
[Typo tolerance](searching.md#typo-tolerance)). Trigrams still cannot tell a typo from a different
word that looks alike: `cable` and `table` are equally close, and a one-letter typo in a very
short word (`mose` for `mouse`, similarity 0.40 against the 0.60 that four letters need) is not
tolerated. Set an explicit `fuzzy_similarity` (a flat value for every word) when that matters.

## Attaching or detaching a partition is not followed

`ALTER TABLE … ATTACH PARTITION` and `DETACH PARTITION` fire no triggers: attached rows are not
indexed and detached rows stay in the index. Run `fuzzphony:reindex <index>` afterwards; it also
removes the orphaned documents. See [partitioned tables](sync.md#partitioned-tables).

## Ranking is approximate beyond `candidate_limit`

With very frequent words, ranking considers the first `candidate_limit` matches, so ordering is
approximate beyond them, and `total` is reported as a lower bound (`2000+`).

Planned fix: an opt-in exact `total`, part of [facets](roadmap.md#facets).
