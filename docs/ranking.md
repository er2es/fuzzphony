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
fuzzy weight only applies when the typo-tolerant branch runs. With the by-length default of
`fuzzy_similarity` a word that does not reach the similarity of its own length scores 0, so it
cannot lift an OR above the words that do match.

`min_score` applies to relevance only. Boosts reorder relevant hits but can never pull an
irrelevant document into the results ([ADR 0004](adr/0004-min-score-on-relevance.md)).

Every hit carries a `ScoreBreakdown`, so "why is this first?" always has an answer.
`fuzzphony:search` prints it as a table.

### Swapped letters

A typed word of 4 to 15 letters on the typo-tolerant branch also matches its neighbour-swapped variants as whole words
(`mosue` finds `mouse`; [Typo tolerance](searching.md#typo-tolerance)). Such a hit scores as the **weakest ordinary
typo hit of that word**: the similarity the word's length needs (0.28 for 4 letters, 0.36 for 5, 0.43 for 6, 0.48 for
7, 0.23 for 8, and so on, or the fixed `fuzzy_similarity`) instead of the variant's own similarity of 1.0. So it ranks
below every exact hit (1.0 for the word, plus its text rank and the bonuses) and never above an ordinary typo hit of the
same word; two swap hits tie and are ordered by the other bonuses and by id. The penalty is the difference between 1.0 and
that floor (0.64 for five letters). In `ScoreBreakdown` it shows as `fuzzySimilarity` at the floor and `textRank` 0.

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
| `fuzzy_similarity` | by word length | trigram word similarity each word needs (lower = more tolerant). Unset (`null`): one typo is tolerated from 4 letters and two from 8; the similarity a word needs is that of the worst such typo plus 0.03, `(n + 1 − 3t) / (n + 1 + 3t)` for `n` letters and `t` typos (0.28 for 4 letters, 0.36 for 5, 0.48 for 7, 0.23 for 8, 0.40 for 12), and 0.6 below 4 letters. A number applies to every word; `null` returns to by length. See [Typo tolerance](searching.md#typo-tolerance) |
| `fuzzy_min_length` | `3` | shorter words must match exactly; a query with no longer word skips typo tolerance |
| `candidate_limit` | `2000` | candidates ranked per branch. When a word has more matches, the full-text branch ranks the best ones by the page's own key (exact up to a scan budget of 100 000 matches; the typo branch takes the first `candidate_limit` matches of its trigram index, a lower bound for a word with more, and a restricted viewer's the best ones within a work budget: [limitation](limitations.md#ranking-of-very-common-words)); a page past it selects `offset + limit` candidates, up to 10 000; `total` becomes a lower bound (`2000+`) |
| `max_query_length` / `max_terms` | `256` / `16` | input limits |
| `did_you_mean` | `true` | a search with fewer than `fallback_below` hits, or one that needed typo tolerance, suggests the spelling of a word the index does not know in `SearchResult::$didYouMean`; never on a tenant-scoped index ([details](searching.md#did-you-mean)) |
| `rescue_empty` | `true` | a search that finds nothing, with a word that has exactly one vocabulary word one edit away (`mpuse` / `mouse`), is searched again with the corrected words and answers with that, flagged in `SearchResult::$correctedFrom` and a warning; never with `did_you_mean: false`, on a tenant-scoped index, for a restricted viewer, or with typo tolerance off ([details](searching.md#zero-result-rescue)) |
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

### Which candidates are ranked

Every branch collects at most `candidate_limit` candidates before it scores them, so the cost of a search does not
depend on how common its words are. A word with fewer matches than that costs what it always did. A word with more:

- **Full-text branch:** the candidates are the best matches by the same key the page is ordered by: the text rank
  (of the fields the viewer may see), the exact-title and prefix bonuses, the boost and recency bonuses, then the
  id. One cheap selection runs first; only when it hits the limit a second one scores the matches (the first
  100 000 of them in index order, fewer for a query of several words: the scan budget, an internal constant) and keeps the best `candidate_limit`. The
  second selection has the same lookup, filter, tenant and field-recheck conditions and, with `min_score`, leaves the
  documents under it out. So `Wireless` (an exact title) comes before 20 000 other `wireless` documents.
- **A page past the candidates** selects the best `offset + limit` instead (at most 10 000), so deep pages are exact;
  `SearchResult::hasMore()` is false at the last page of the candidates.
- **Typo-tolerant branch:** the first `candidate_limit` matches the trigram index returns (the window of the page when that is
  larger), cheap and a lower bound for a word with more matches than that. A document the full-text branch finds keeps its typo
  score, so an exact word never ranks below its look-alikes for missing those first matches. A restricted viewer's typo branch
  selects the best by the same key as the full-text branch instead, among the first matches of a work budget (30 000 divided by the
  number of words for short texts, fewer for long ones), so that a field the viewer cannot see never decides the candidates. Ties are
  broken by the id.
- **Facets** of a capped search count the very candidates the hits come from (lower bounds); **`exactCounts()`** counts every match.

See also [ADR 0005](adr/0005-candidate-limit.md) on the candidate limit, and the
[known limitations](limitations.md#ranking-of-very-common-words) of typo tolerance and `candidate_limit`.
