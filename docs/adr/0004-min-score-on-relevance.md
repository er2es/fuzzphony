# 4. `min_score` applies to relevance only

Status: accepted

## Context
If boosts (popularity, recency, exact/prefix bonuses) counted towards the threshold, a popular but
irrelevant document could pass it, and changing a boost weight would silently change which documents
match at all.

## Decision
`relevance = text × rank + fuzzy × similarity` is compared with `min_score`; bonuses only affect ordering.

## Consequences
+ "What matches" and "in which order" are independent knobs, which makes tuning predictable.
− `min_score` values are on the 0..(text+fuzzy) scale, not on the final score scale (documented).
