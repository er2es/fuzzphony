# 5. Bounded candidate sets

Status: accepted

## Context
Ranking every match of a very frequent word ("the", "usb") on millions of rows makes latency
depend on the data, not on the page size.

## Decision
Each branch (full-text, trigram) collects at most `candidate_limit` (default 2000) candidates via its
index before scoring. When a branch hits the limit, `SearchResult::$totalIsLowerBound` is true
(UI shows "2000+").

## Consequences
+ Predictable latency for every query.
− Ordering among extremely common matches is approximate; raise the limit per query when needed.
