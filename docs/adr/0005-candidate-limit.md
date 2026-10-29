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
The window grows with the page: `max(candidate_limit, offset + limit)`, at most 10 000, for the typo-tolerant branch and filter-only
browsing too. Exact counts keep the cheap selection. The facets of a capped search count the candidates the page was chosen from.

The typo-tolerant branch keeps the cheap selection (the first matches of its trigram index) for everyone whose rows are all
visible: an unrestricted viewer and a tenant. Choosing the best typo candidates (a pool of the closest spellings, or an ordered
second selection) was tried for them in 0.9.9 and removed: it cost 5 to 10 times the cheap search (0.3 to 0.5 s on 200 000 products,
2 to 3 s on a wide index) to improve the order of a lower bound that shows them nothing they could not read. What it was for is kept
where it matters: a full-text match scores as the typo branch would score it, whether or not it is among the first typo candidates, and a
restricted viewer, for whom the first matches in the table's physical order are shaped by the size of the fields it cannot see, gets the
ordered second selection within a work budget (rows, and the stored size of the text each row rechecks).

## Consequences
+ Predictable latency for every query: a word under the limit costs what it always did, a word over it one more pass
  over at most 100 000 matches.
+ The best match of a common word is found; pages past the first candidates are exact up to 10 000 hits.
− The ranking is exact only up to 100 000 matches (30 000 or fewer in a restricted viewer's typo-tolerant branch); raise the limit per
  query (up to the cap) or make the query more specific when needed.
− A typo word with more matches than the limit contributes the first of them in the table's order, not necessarily the closest
  spellings (a lower bound, like `total`); a restricted viewer's search of such a word costs one more selection.
