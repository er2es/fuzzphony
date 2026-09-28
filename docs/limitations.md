# Known limitations

What Fuzzphony does not do well yet, and the planned fix for each. Back to the
[README](../README.md).

Each of these has a planned fix on the [roadmap](roadmap.md).

## Typo tolerance is lenient

Typo tolerance is per word and deliberately lenient. At the default `fuzzy_similarity` of 0.3 a
correctly spelled word also matches similar words (`mouse` is trigram-close to `monitor` and
`mower`), so `wireles mouse` also lists wireless monitors, ranked below the mice. With very
frequent words these near-misses can use up `candidate_limit` before ranking. Raise
`fuzzy_similarity` (0.4 to 0.5 is stricter) or `candidate_limit` when that matters.

Planned fix: [length-aware typo tolerance](roadmap.md#length-aware-typo-tolerance).

## Ranking is approximate beyond `candidate_limit`

With very frequent words, ranking considers the first `candidate_limit` matches, so ordering is
approximate beyond them, and `total` is reported as a lower bound (`2000+`).

Planned fix: an opt-in exact `total`, part of [facets](roadmap.md#facets).
