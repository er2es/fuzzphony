# 5. Bounded candidate sets

Status: accepted

## Context
Ranking every match of a very frequent word ("the", "usb") on millions of rows makes latency
depend on the data, not on the page size.

## Decision
Each branch (full-text, trigram) collects at most `candidate_limit` (default 2000) candidates via its
index before scoring. When a branch hits the limit, `SearchResult::$totalIsLowerBound` is true
(UI shows "2000+"). The limit itself is capped at `Thresholds::MAX_CANDIDATE_LIMIT` (10 000), as are
`max_query_length` (1 024) and `max_terms` (64), so an override forwarded from a request can never
switch the cost limits off.

Which candidates (0.9.8). The first version took the first matches the index returned, so with a very common word the
best match could be missing. The full-text branch now runs the cheap selection first and, only when it hit the limit, a
second one that scores the matches and keeps the best by the key the page is ordered by (one statement, the second
select behind a guard that is false for every word under the limit). The scores are computed for the first 100 000
matches only (an internal scan budget, not a setting), so the cost of a search stays bounded however common a word is.
The window grows with the page: `max(candidate_limit, offset + limit)`, at most 10 000. Facets, exact counts and the
typo-tolerant branch keep the cheap selection (the latter until 0.9.9).

## Consequences
+ Predictable latency for every query: a word under the limit costs what it always did, a word over it one more pass
  over at most 100 000 matches.
+ The best match of a common word is found; pages past the first candidates are exact up to 10 000 hits.
− The ranking is exact only up to 100 000 matches (and in the typo-tolerant branch not yet); raise the limit per query
  (up to the cap) or make the query more specific when needed.
− `total` and the facet counts of a very common word come from different candidate sets.
