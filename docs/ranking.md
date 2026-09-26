# Ranking and thresholds

How hits are scored, how to rank one index differently per use case, and the thresholds that
decide what matches. Back to the [README](../README.md).

## Ranking

```
relevance = text  × ts_rank_cd(weights A..D)       (0..1)
          + fuzzy × per-word similarity                  (0..1)

score     = relevance
          + exact_bonus    (primary field equals the query)
          + prefix_bonus   (primary field starts with the query)
          + boost   × boost column
          + recency × 2^(−age / half_life)
```

The per-word similarity (`r_fuzzy`, `ScoreBreakdown::$fuzzySimilarity`) follows the query. A word
scores 1.0 when it matches exactly, and its trigram `word_similarity` against the fuzzy fields
otherwise. AND averages its words, OR takes the best one, negations do not score. So a document
that matches both words of `wireles mouse` well outranks one that barely matches one of them. The
fuzzy weight only applies when the typo-tolerant branch runs.

`min_score` applies to relevance only. Boosts reorder relevant hits but can never pull an
irrelevant document into the results ([ADR 0004](adr/0004-min-score-on-relevance.md)).

Every hit carries a `ScoreBreakdown`, so "why is this first?" always has an answer.
`fuzzphony:search` prints it as a table.

## Profiles

Profiles let one index rank differently per use case:

```yaml
fuzzphony:
  indexes:
    products:
      profiles:
        popular: { boost: 0.05, recency: 0.3, recency_half_life_days: 14 }
        strict:  { fuzzy: 0, exact_bonus: 1.0 }
```

```php
$fuzzphony->in('products')->query('mouse')->profile('popular')->get();
```

`profile()` throws on an unknown name. Whitelist the value if it comes from a request.

## Per-query tuning

Any profile value can be overridden for one query. The demo's playground does this with sliders:

```php
$fuzzphony->in('products')->query('mouse')->profile('popular')->ranking(['boost' => 0.1, 'fuzzy' => 0.8])->get();
```

## Thresholds

| Option | Default | Meaning |
|---|---|---|
| `min_score` | `0.0` | minimum relevance a hit needs |
| `fuzzy_mode` | `fallback` | `always`, `fallback` (only when exact matching finds few hits) or `never` |
| `fallback_below` | `5` | in fallback mode: fewer exact hits than this triggers typo tolerance |
| `fuzzy_similarity` | `0.3` | trigram word similarity each word needs (lower = more tolerant) |
| `fuzzy_min_length` | `3` | shorter words must match exactly; a query with no longer word skips typo tolerance |
| `candidate_limit` | `2000` | max candidates ranked per branch; `total` becomes a lower bound (`2000+`) |
| `max_query_length` / `max_terms` | `256` / `16` | input limits |
| `relax_when_empty` | `true` | a multi-word query that finds nothing drops the words that match nothing and says so in `warnings` ([details](searching.md#empty-result-relaxation)); independent of `fuzzy_mode` |

Set them per index (YAML `thresholds:`) or per query:

```php
->thresholds(['min_score' => 0.1, 'fuzzy_mode' => 'always'])
```

Invalid keys fail immediately with the list of allowed ones.

The cost limits have hard caps that no setting can exceed:

| Limit | Cap | Constant |
|---|---|---|
| `candidate_limit` | 10 000 | `Thresholds::MAX_CANDIDATE_LIMIT` |
| `max_query_length` | 1 024 | `Thresholds::MAX_QUERY_LENGTH` |
| `max_terms` | 64 | `Thresholds::MAX_TERMS` |

Larger values, and an unknown `fuzzy_mode`, throw `InvalidDefinition`. Thresholds taken from a
request can tighten the limits but never remove them.

See also [ADR 0005](adr/0005-candidate-limit.md) on the candidate limit, and the
[known limitations](limitations.md) of typo tolerance and `candidate_limit`.
