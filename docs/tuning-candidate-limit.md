# Tuning `candidate_limit`

How many candidates a search ranks, and what to set when the indexed texts are long. Back to the [README](../README.md); the
rule itself is in [Ranking](ranking.md#which-candidates-are-ranked).

## What it costs

A search ranks at most `candidate_limit` documents per branch (2000 by default, at most 10 000) before it returns the page. The cost
of ranking one candidate is the size of the text it reads, so the limit is a time budget:

- **Short texts** (names, titles, a few hundred characters): about 1 microsecond per scanned match for the strict branch and about
  10 microseconds per typo candidate. The default is right; a common word on 200 000 products costs 35 to 45 ms
  ([Benchmarks](benchmarks.md#common-words)).
- **Long texts in a typo-tolerant field** (a description, an article body, a manual: thousands of words): every candidate that holds
  one is rechecked against the whole text, so the cost grows with the text, not with the number of documents. Measured on 3 000
  documents, 60 of them with a 17 000-word typo-tolerant field (one search for a word that every title has, `fuzzy_mode: always`,
  median of 3, warm, local PostgreSQL):

| `candidate_limit` | `common` (exact word) | `comon` (typo) |
|---:|---:|---:|
| 2000 (default) | 3 800 ms | 4 400 ms |
| 500 | 950 ms | 1 100 ms |
| 100 | 200 ms | 230 ms |
| 20 | 17 ms | 9 ms |

  The time is nearly proportional to the limit: with the default, a very common word costs 2.7 to 4 s on such an index. A restricted
  viewer pays for the text it may see only; a hidden text does not move the limit
  ([Security](security.md#the-limits-stated-honestly)).

## What to set

1. **Measure first.** `explain()` (or `fuzzphony:search --explain --analyze`) shows the time of the statement; try the limits
   above with `->thresholds(['candidate_limit' => 200])` on your own data and your most common words.
2. **Do not make the field typo-tolerant when it need not be.** `fuzzy: true` on a long text keeps a trigram copy of it and
   rechecks it. Typo tolerance on the title and the name, full-text only on the description, is usually what a search needs, and the
   long field then costs nothing extra.
3. **Lower the limit for an index with long typo-tolerant texts** (100 to 500 is a good range for texts of thousands of words),
   per index in YAML (`thresholds: { candidate_limit: 200 }`) or per query. The ranking stays exact for what is ranked: the best
   candidates by the page's own sort key; only the pages past the limit differ (`total` is `200+`, a page past it is still served,
   up to 10 000 hits, with `offset + limit` candidates).
4. **Keep the default** for names, titles and short descriptions, and when the common words of your data are not common at all.
5. **Raise it** (up to 10 000) only when users page deep into a very common word and the texts are short; every step costs time
   linearly.

A low limit does not hide the best match of a common word (the exact title and the repeated word come first), and a pager that
follows `hasMore()` stops at the limit. If users need every match, offer a filter or a more specific query instead of a bigger
limit; `exactCounts()` counts every match for the total and the facets without ranking more candidates, at the price of reading
them.

A size-aware limit that does this automatically is on the list for a later release; until then it is a setting.
