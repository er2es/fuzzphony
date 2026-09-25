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

## Consequences
+ Predictable latency for every query.
− Ordering among extremely common matches is approximate; raise the limit per query (up to the cap) when needed.
